<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Rut\Rut;
use RuntimeException;

readonly class BoletaRestGateway
{
    public function __construct(
        protected Http $http,
        protected EnvironmentResolver $environment,
        protected CertificateResolverInterface $certificates,
        protected XmlSigner $signer,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Get an authentication token for the given issuer RUT.
     */
    public function getToken(Rut $issuerRut, ?string $authUrl = null): string
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return 'fake-token';
        }

        // 1. Get Seed
        $seedResponse = $this->http->get($authUrl.'/boleta.electronica.semilla');

        if ($seedResponse->failed()) {
            throw new RuntimeException('Failed to get seed from SII.');
        }

        $seedDocument = $this->xml->document('1.0', 'UTF-8');
        $seedDocument->loadXML($seedResponse->body());
        $seed = $seedDocument->getElementsByTagName('SEMILLA')->item(0)?->nodeValue;

        if (! $seed) {
            throw new RuntimeException('Invalid seed response from SII.');
        }

        // 2. Create XML and sign it
        $tokenXml = $this->xml->document('1.0', 'UTF-8');
        $getToken = $tokenXml->createElement('getToken');
        $getToken->setAttribute('ID', 'GetToken'); // Required by XmlSigner
        $item = $tokenXml->createElement('item');
        $semilla = $tokenXml->createElement('Semilla', $seed);

        $item->appendChild($semilla);
        $getToken->appendChild($item);
        $tokenXml->appendChild($getToken);

        $certificate = $this->certificates->resolve($issuerRut);
        if (! $certificate) {
            throw new RuntimeException('No digital certificate resolved for issuer '.$issuerRut->formatBasic());
        }

        $this->signer->sign($getToken, $certificate);

        $tokenXmlString = $tokenXml->saveXML();
        $tokenXmlString = str_replace(["\r", "\n"], '', $tokenXmlString);
        $tokenXmlString = str_replace(
            '<?xml version="1.0" encoding="UTF-8"?>',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n",
            $tokenXmlString
        );

        // 3. POST /boleta.electronica.token
        $tokenResponse = $this->http->withBody($tokenXmlString, 'application/xml')
            ->post($authUrl.'/boleta.electronica.token');

        if ($tokenResponse->failed()) {
            throw new RuntimeException('Failed to get token from SII.');
        }

        $tokenDocument = $this->xml->document();
        $tokenDocument->loadXML($tokenResponse->body());
        $token = $tokenDocument->getElementsByTagName('TOKEN')->item(0)?->nodeValue;

        if (! $token) {
            throw new RuntimeException('Invalid token response from SII.');
        }

        return $token;
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

        $token = $this->getToken($envelope->issuer_rut, $authUrl);

        // 4. POST /boleta.electronica.envio
        $issuer = $envelope->issuer_rut;
        $sender = $envelope->sender_rut;

        $uploadResponse = $this->http->baseUrl($authUrl)
            ->withCookies(['TOKEN' => $token], parse_url($authUrl, PHP_URL_HOST))
            ->withHeaders(['User-Agent' => 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)'])
            ->timeout(60)
            ->post('/boleta.electronica.envio', [
                'rutSender' => $sender->num,
                'dvSender' => $sender->vd,
                'rutCompany' => $issuer->num,
                'dvCompany' => $issuer->vd,
                'archivo' => base64_encode($signedXml),
            ]);

        if ($uploadResponse->unauthorized()) {
            throw new RuntimeException('SII Upload rejected the authentication token (401).');
        }

        if ($uploadResponse->failed()) {
            throw new RuntimeException('SII Upload request failed with status '.$uploadResponse->status().'.');
        }

        $trackId = $uploadResponse->json('trackid');

        if (! $trackId) {
            throw new RuntimeException('SII Upload response did not contain a valid TrackID.');
        }

        return (string) $trackId;
    }

    /**
     * Queries the SII REST API for the status of an envelope track ID.
     */
    public function trackStatus(SiiDteEnvelope $envelope, ?string $authUrl = null): array
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return ['estado' => 'REC', 'glosa' => 'Faked status'];
        }

        $token = $this->getToken($envelope->issuer_rut, $authUrl);
        $issuer = $envelope->issuer_rut;

        $response = $this->http->baseUrl($authUrl)
            ->withCookies(['TOKEN' => $token], parse_url($authUrl, PHP_URL_HOST))
            ->withHeaders(['User-Agent' => 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)'])
            ->timeout(30)
            ->get(sprintf('/boleta.electronica.envio/%s-%s-%s', $issuer->num, $issuer->vd, $envelope->track_id));

        if ($response->unauthorized()) {
            throw new RuntimeException('SII Status Query rejected the authentication token (401).');
        }

        if ($response->failed()) {
            throw new RuntimeException('SII Status Query failed with status '.$response->status().'.');
        }

        return $response->json() ?? [];
    }

    /**
     * Queries the SII REST API for the status of an individual boleta DTE.
     */
    public function documentStatus(SiiDte $dte, ?string $authUrl = null): array
    {
        $authUrl ??= $this->environment->resolve()->restBaseUrl();

        if ($authUrl === null) {
            return ['estado' => 'REC', 'glosa' => 'Faked status'];
        }

        $token = $this->getToken($dte->issuer_rut, $authUrl);
        $issuer = $dte->issuer_rut;

        $receiverNum = '0';
        $receiverVd = '0';

        if ($dte->receiver_rut && $dte->receiver_rut->formatRaw() !== '0') {
            $receiverNum = $dte->receiver_rut->num;
            $receiverVd = $dte->receiver_rut->vd;
        }

        $monto = $dte->amount_total;
        $fecha = $dte->created_at->format('d-m-Y'); // Format expected? Wait, OpenAPI says: monto, fecha. Let's see query params or path?

        // OpenAPI path: /boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado
        $response = $this->http->baseUrl($authUrl)
            ->withCookies(['TOKEN' => $token], parse_url($authUrl, PHP_URL_HOST))
            ->withHeaders(['User-Agent' => 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)'])
            ->timeout(30)
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
            throw new RuntimeException('SII Document Status Query rejected the authentication token (401).');
        }

        if ($response->failed()) {
            throw new RuntimeException('SII Document Status Query failed with status '.$response->status().'.');
        }

        return $response->json() ?? [];
    }
}
