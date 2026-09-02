<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\SiiEndpoints;
use Laragear\Dte\Support\TokenAuthenticator;
use Laragear\Rut\Rut;
use RuntimeException;
use function sprintf;

/**
 * Uploads IECV XMLs to the SII (Only for certification environments).
 */
class IecvUploadGateway
{
    /**
     * SII upload endpoint path.
     */
    protected const string UPLOAD_PATH = UploadGateway::UPLOAD_PATH;

    /**
     * Create a new Iecv Upload Gateway instance.
     */
    public function __construct(
        protected readonly Http $http,
        protected readonly TokenAuthenticator $authenticator,
        protected readonly EnvironmentResolver $environment,
    ) {
        //
    }

    /**
     * Upload an IECV XML to the SII and return the TrackID.
     */
    public function upload(Rut $issuer, Rut $sender, string $signedXml, ?string $baseUrl = null): string
    {
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();

        if ($baseUrl === null) {
            return 'fake-iecv-track-id-'.time();
        }

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
                    'iecv.xml',
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
            throw new RuntimeException('SII Upload rejected the IECV: '.$matches[1]);
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
