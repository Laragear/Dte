<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Http\Client\Factory as Http;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Rut\Rut;
use RuntimeException;

/**
 * Performs the raw SII REST authentication flow (seed → sign → token
 * exchange) for the Boleta REST services, without any caching.
 *
 * Pure transport: the TokenAuthenticator calls this and caches the result.
 * This class is deliberately separate from BoletaRestGateway so the
 * authenticator never depends on a gateway that depends on the authenticator.
 */
class RestAuthGateway
{
    /**
     * The XML declaration parameters for the signed token request.
     */
    protected const string XML_VERSION = '1.0';

    protected const string XML_ENCODING = 'UTF-8';

    /**
     * Create a new REST Auth Gateway instance.
     */
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
     * Perform the raw SII REST authentication flow and return the token string.
     */
    public function fetchToken(Rut $issuerRut, ?string $authUrl = null): string
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

        $seedDocument = $this->xml->document(static::XML_VERSION, static::XML_ENCODING);
        $seedDocument->loadXML($seedResponse->body());
        $seed = $seedDocument->getElementsByTagName('SEMILLA')->item(0)?->nodeValue;

        if (!$seed) {
            throw new RuntimeException('Invalid seed response from SII.');
        }

        // 2. Create XML and sign it
        $tokenXml = $this->xml->document(static::XML_VERSION, static::XML_ENCODING);
        $getToken = $tokenXml->createElement('getToken');
        $getToken->setAttribute('ID', 'GetToken'); // Required by XmlSigner
        $item = $tokenXml->createElement('item');
        $semilla = $tokenXml->createElement('Semilla', $seed);

        $item->appendChild($semilla);
        $getToken->appendChild($item);
        $tokenXml->appendChild($getToken);

        $certificate = $this->certificates->resolve($issuerRut);
        if (!$certificate) {
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

        if (!$token) {
            throw new RuntimeException('Invalid token response from SII.');
        }

        return $token;
    }
}
