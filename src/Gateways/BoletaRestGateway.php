<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Laragear\Dte\Data\TrackStatus;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\SiiEndpoints;
use Laragear\Dte\Support\TokenAuthenticator;
use Laragear\Rut\Rut;
use RuntimeException;

class BoletaRestGateway
{
    /**
     * Response returned when there is no REST base URL (non-production
     * environments): the SII "received" status without a real envelope.
     */
    protected const array FAKE_TRACK_STATUS = ['estado' => 'REC', 'glosa' => 'Faked status'];

    /**
     * Create a new Boleta Rest Gateway instance.
     */
    public function __construct(
        protected Http $http,
        protected EnvironmentResolver $environment,
        protected TokenAuthenticator $authenticator,
    ) {
        //
    }

    /**
     * Get an authentication token string for the given issuer RUT.
     */
    public function getToken(Rut $issuerRut, ?string $authUrl = null): string
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return 'fake-token';
        }

        // Delegates to the TokenAuthenticator which handles cache read + SII auth.
        // Token fetching and caching are owned by the TokenAuthenticator; on a 401
        // the refresh-and-retry loop asks it to refresh the REST token and retries.
        return $this->authenticator->restToken($issuerRut, $authUrl);
    }

    /**
     * Upload a signed envelope XML to the SII REST API and return the TrackID.
     */
    public function upload(SiiDteEnvelope $envelope, string $signedXml, ?string $authUrl = null): string
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return 'fake-track-id-'.$envelope->getKey();
        }

        // SII uses dedicated servers for uploads (pangal/rahue vs apicert/api).
        $uploadUrl = $this->environment->resolve()->restUploadBaseUrl();

        $issuer = $envelope->issuer_rut;

        return $this->authenticator->retryRestWithFreshToken(function () use (
            $envelope,
            $signedXml,
            $authUrl,
            $uploadUrl,
            $issuer
        ): string {
            $token = $this->authenticator->restToken($issuer, $authUrl);
            $sender = $envelope->sender_rut;

            /**
             * We do a POST to /boleta.electronica.envio (dedicated upload server).
             * Uses multipart/form-data with file attachment as required by SII.
             *
             * @see https://www4c.sii.cl/bolcoreinternetui/api/openapi.yaml (EnvioPost schema)
             */
            $uploadResponse = $this->http->baseUrl($uploadUrl)
                ->withCookies([SiiEndpoints::TOKEN_COOKIE => $token], parse_url($uploadUrl, PHP_URL_HOST))
                ->withHeaders([SiiEndpoints::USER_AGENT_HEADER => SiiEndpoints::USER_AGENT])
                ->timeout(60)
                ->withOptions($this->tlsOptions())
                ->attach('archivo', $signedXml, 'envio.xml', ['Content-Type' => 'application/xml'])
                ->post('/boleta.electronica.envio', [
                    'rutSender' => $sender->num,
                    'dvSender' => $sender->vd,
                    'rutCompany' => $issuer->num,
                    'dvCompany' => $issuer->vd,
                ]);

            if ($uploadResponse->unauthorized()) {
                throw new TokenInvalidException('SII Upload rejected the authentication token (401).');
            }

            if ($uploadResponse->failed()) {
                throw new RuntimeException('SII Upload request failed with status '.$uploadResponse->status().'.');
            }

            $trackId = $uploadResponse->json('trackid');

            if (!$trackId) {
                throw new RuntimeException('SII Upload response did not contain a valid TrackID.');
            }

            return (string) $trackId;
        }, $issuer);
    }

    /**
     * Queries the SII REST API for the status of an envelope track ID.
     *
     * @see https://www4c.sii.cl/bolcoreinternetui/api/openapi.yaml (X-Retry-After header)
     */
    public function trackStatus(SiiDteEnvelope $envelope, ?string $authUrl = null): TrackStatus
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        // Returns a TrackStatus DTO that includes the X-Retry-After header value for respecting SII rate limits.
        if ($authUrl === null) {
            return new TrackStatus(status: 'REC', raw: static::FAKE_TRACK_STATUS);
        }

        $issuer = $envelope->issuer_rut;

        return $this->authenticator->retryRestWithFreshToken(function () use (
            $envelope,
            $authUrl,
            $issuer
        ): TrackStatus {
            $token = $this->authenticator->restToken($issuer, $authUrl);

            $response = $this->http->baseUrl($authUrl)
                ->withCookies([SiiEndpoints::TOKEN_COOKIE => $token], parse_url($authUrl, PHP_URL_HOST))
                ->withHeaders([SiiEndpoints::USER_AGENT_HEADER => SiiEndpoints::USER_AGENT])
                ->timeout(30)
                ->withOptions($this->tlsOptions())
                ->get(sprintf('/boleta.electronica.envio/%s-%s-%s', $issuer->num, $issuer->vd, $envelope->track_id));

            if ($response->unauthorized()) {
                throw new TokenInvalidException('SII Status Query rejected the authentication token (401).');
            }

            if ($response->failed()) {
                throw new RuntimeException('SII Status Query failed with status '.$response->status().'.');
            }

            // X-Retry-After header specifies seconds to wait before next poll.
            $retryAfter = (int) ($response->header('X-Retry-After') ?? 10);

            return TrackStatus::fromResponse($response->json() ?? [], $retryAfter);
        }, $issuer);
    }

    /**
     * Queries the SII REST API for the status of an individual boleta DTE.
     */
    public function documentStatus(SiiDte $dte, ?string $authUrl = null): array
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return static::FAKE_TRACK_STATUS;
        }

        $issuer = $dte->issuer_rut;

        return $this->authenticator->retryRestWithFreshToken(function () use ($dte, $authUrl, $issuer): array {
            $token = $this->authenticator->restToken($issuer, $authUrl);

            $receiverNum = '0';
            $receiverVd = '0';

            if ($dte->receiver_rut && $dte->receiver_rut->formatRaw() !== '0') {
                $receiverNum = $dte->receiver_rut->num;
                $receiverVd = $dte->receiver_rut->vd;
            }

            $monto = $dte->amount_total;
            $fecha = $dte->issued_on->format('d-m-Y');

            // OpenAPI path: /boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado
            $response = $this->http->baseUrl($authUrl)
                ->withCookies([SiiEndpoints::TOKEN_COOKIE => $token], parse_url($authUrl, PHP_URL_HOST))
                ->withHeaders([SiiEndpoints::USER_AGENT_HEADER => SiiEndpoints::USER_AGENT])
                ->timeout(30)
                ->withOptions($this->tlsOptions())
                ->get(sprintf(
                    '/boleta.electronica/%s-%s-%s-%s/estado',
                    $issuer->num,
                    $issuer->vd,
                    $dte->document_type->value,
                    $dte->folio
                ), [
                    'rut_receptor' => $receiverNum.'-'.$receiverVd,
                    'monto' => $monto,
                    'fechaEmision' => $fecha,
                ]);

            if ($response->unauthorized()) {
                throw new TokenInvalidException('SII Document Status Query rejected the authentication token (401).');
            }

            if ($response->failed()) {
                throw new RuntimeException('SII Document Status Query failed with status '.$response->status().'.');
            }

            return $response->json() ?? [];
        }, $issuer);
    }

    /**
     * Return HTTP client options that enforce TLS 1.2+ connections.
     *
     * @see knowledge/documentation/instructivo_emision.md Section 2 (TLS 1.2+)
     */
    protected function tlsOptions(): array
    {
        return [
            'verify' => true,
            'curl' => [
                // SII requires TLS 1.2 or higher for all API connections.
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ];
    }
}
