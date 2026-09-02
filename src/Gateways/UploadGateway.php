<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\SiiEndpoints;
use Laragear\Dte\Support\TokenAuthenticator;
use RuntimeException;
use function sprintf;

/**
 * Uploads signed DTE envelopes to the SII via their upload service.
 */
class UploadGateway
{
    /**
     * SII upload endpoint path.
     */
    public const string UPLOAD_PATH = '/cgi_dte/UPL/DTEUpload';

    /**
     * Create a new Upload Gateway instance.
     */
    public function __construct(
        protected Http $http,
        protected TokenAuthenticator $authenticator,
        protected EnvironmentResolver $environment,
    ) {
        //
    }

    /**
     * Upload a signed envelope XML to the SII and return the TrackID.
     */
    public function upload(SiiDteEnvelope $envelope, string $signedXml, ?string $baseUrl = null): string
    {
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();

        if ($baseUrl === null) {
            return 'fake-track-id-'.$envelope->getKey();
        }

        $issuer = $envelope->issuer_rut;
        $sender = $envelope->sender_rut;

        return $this->authenticator->retryWithFreshToken(function () use (
            $issuer,
            $sender,
            $signedXml,
            $baseUrl
        ): string {
            $token = $this->authenticator->token($issuer, $baseUrl);

            $response = $this->client($token->value, $baseUrl)
                ->attach(
                    'archivo',
                    $signedXml,
                    'envio.xml',
                )
                ->post(self::UPLOAD_PATH, [
                    'rutSender' => $sender->num,
                    'dvSender' => $sender->vd,
                    'rutCompany' => $issuer->num,
                    'dvCompany' => $issuer->vd,
                ]);

            if ($response->unauthorized()) {
                throw new TokenInvalidException('SII Upload rejected the authentication token (401).');
            }

            if ($response->failed()) {
                throw new RuntimeException(sprintf(
                    'SII Upload request failed with status %d.',
                    $response->status(),
                ));
            }

            return $this->parseTrackId($response->body());
        }, $issuer);
    }

    /**
     * Extract the TrackID from the SII upload response body.
     */
    protected function parseTrackId(string $responseBody): string
    {
        if (preg_match('/<TRACKID>(\d+)<\/TRACKID>/i', $responseBody, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<STATUS>([^<]+)<\/STATUS>/i', $responseBody, $matches) && $matches[1] !== '0') {
            throw new RuntimeException('SII Upload rejected the envelope: '.$matches[1]);
        }

        throw new RuntimeException('SII Upload response did not contain a valid TrackID.');
    }

    /**
     * Build an authenticated multipart HTTP client for the SII upload service.
     */
    protected function client(string $token, string $baseUrl): PendingRequest
    {
        return $this->http
            ->createPendingRequest()
            ->baseUrl($baseUrl)
            ->withCookies([SiiEndpoints::TOKEN_COOKIE => $token], parse_url($baseUrl, PHP_URL_HOST))
            ->withHeaders([SiiEndpoints::USER_AGENT_HEADER => SiiEndpoints::USER_AGENT])
            ->timeout(60)
            ->asMultipart();
    }
}
