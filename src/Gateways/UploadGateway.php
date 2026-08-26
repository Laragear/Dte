<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Models\SiiDteEnvelope;
use RuntimeException;

use function sprintf;

/**
 * Uploads signed DTE envelopes to the SII via their upload service.
 */
final readonly class UploadGateway
{
    /**
     * SII upload endpoint path.
     */
    protected const string UPLOAD_PATH = '/cgi_dte/UPL/DTEUpload';

    public function __construct(
        protected Http $http,
        protected TokenProviderInterface $tokens,
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

        $token = $this->tokens->token($issuer, $baseUrl);

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
            throw new RuntimeException('SII Upload rejected the authentication token (401).');
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'SII Upload request failed with status %d.',
                $response->status(),
            ));
        }

        return $this->parseTrackId($response->body());
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
            ->withCookies(['TOKEN' => $token], parse_url($baseUrl, PHP_URL_HOST))
            ->timeout(60)
            ->asMultipart();
    }
}
