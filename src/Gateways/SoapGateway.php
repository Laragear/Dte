<?php

namespace Laragear\Dte\Gateways;

use DOMElement;
use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\SiiAuthState;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\SiiAuthenticationException;
use Laragear\Dte\Gateways\Exceptions\SiiSeedUnavailableException;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use RuntimeException;
use SoapClient;
use SoapHeader;
use Throwable;
use function base64_encode;
use function is_array;
use function is_object;
use function sha1;
use function sleep;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;

/**
 * SOAP Gateway for communicating with SII legacy Web Services.
 *
 * Pure transport layer: authenticate() performs the raw SII auth flow
 * (CrSeed → GetTokenFromSeed) and query() dispatches authenticated SOAP
 * calls with a token provided by the caller. Token caching and lifetime are
 * owned by TokenRepository via TokenAuthenticator — this class has no
 * token-fetching or caching responsibility.
 */
class SoapGateway
{
    /**
     * The XML element carrying the response state in SII auth responses.
     */
    protected const string ESTADO_ELEMENT = 'ESTADO';

    /**
     * The key of the private key inside the PKCS#12 read result.
     */
    protected const string PEM_PRIVATE_KEY = 'pkey';

    /**
     * Progressive backoff (in seconds) used when SII asks to retry later.
     *
     * @var list<int>
     */
    protected const array RETRY_DELAYS = [0, 5, 10, 15];

    /**
     * Create a new SOAP Gateway instance.
     */
    public function __construct(
        protected Filesystem $file,
        protected CertificateResolverInterface $certificates,
        protected EnvironmentResolver $environment,
        protected SoapProxy $soapProxy,
        protected OpenSslProxy $openSsl,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Authenticate with SII to get a new token (CrSeed → GetTokenFromSeed).
     *
     * This is the raw SII auth flow without any caching. The TokenAuthenticator
     * calls this method and is responsible for caching the result.
     */
    public function authenticate(
        Rut $issuer,
        ?DigitalCertificate $certificate = null,
        ?string $baseUrl = null
    ): string {
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();

        if ($baseUrl === null) {
            throw new RuntimeException('Cannot authenticate because no SOAP Base URL is available in this environment.');
        }

        $certificate ??= $this->certificates->resolve($issuer);

        if ($certificate === null) {
            throw new RuntimeException('No digital certificate resolved for issuer '.$issuer->formatBasic());
        }

        return $this->exchangeSeedForToken(
            $baseUrl,
            $this->buildSignedSeedXml($this->fetchSeed($baseUrl), $certificate)
        );
    }

    /**
     * Execute a generic SOAP query against the specified service using the
     * given token.
     *
     * Token fetching and caching is the responsibility of the caller
     * (TokenAuthenticator).
     */
    public function query(
        Token $token,
        string $service,
        string $action,
        array $arguments = [],
        ?string $baseUrl = null
    ): mixed {
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();
        $wsdlUrl = $this->resolveWsdlUrl($baseUrl, $service);

        $client = $this->newSoapClient($wsdlUrl);

        // Add authentication header
        $client->__setSoapHeaders(new SoapHeader(
            'http://www.sii.cl/ws/',
            'Token',
            $token->value,
        ));

        return $client->__soapCall($action, $arguments);
    }

    /**
     * Fetch a semilla (seed) from the SII CrSeed service.
     */
    protected function fetchSeed(string $baseUrl): string
    {
        $client = $this->newSoapClient($this->resolveWsdlUrl($baseUrl, 'CrSeed'));

        [$estado, $seed] = $this->callSeed($client);

        if ($this->state($estado) === SiiAuthState::Ok) {
            return $seed;
        }

        // SeedGenerationError: "Error no genera Semilla" — retry the SOAP call once, then give up.
        if ($this->state($estado) === SiiAuthState::SeedGenerationError) {
            [$estado, $seed] = $this->callSeed($client);

            if ($this->state($estado) === SiiAuthState::Ok) {
                return $seed;
            }

            throw new RuntimeException("SII seed is unavailable after retry (state '$estado').");
        }

        // SeedDatabaseError: "Error en Base de Datos SII" — retry later with progressive backoff.
        if ($this->state($estado) === SiiAuthState::SeedDatabaseError) {
            return $this->seedBackoff($client);
        }

        throw new RuntimeException("SII returned an unexpected seed state '$estado'.");
    }

    /**
     * Retry fetching the seed with progressive backoff on SeedDatabaseError.
     */
    protected function seedBackoff(SoapClient $client): string
    {
        foreach (self::RETRY_DELAYS as $delay) {
            $this->sleepFor($delay);

            [$estado, $seed] = $this->callSeed($client);

            if ($this->state($estado) === SiiAuthState::Ok) {
                return $seed;
            }

            if ($this->state($estado) !== SiiAuthState::SeedDatabaseError) {
                break;
            }
        }

        throw new SiiSeedUnavailableException('SII seed is unavailable after multiple retries.');
    }

    /**
     * Exchange a signed seed XML for an authentication token.
     */
    protected function exchangeSeedForToken(string $baseUrl, string $signedXml): string
    {
        $client = $this->newSoapClient($this->resolveWsdlUrl($baseUrl, 'GetTokenFromSeed'));

        [$estado, $token] = $this->callToken($client, $signedXml);

        if ($this->state($estado) === SiiAuthState::TokenPending) {
            return $this->tokenBackoff($client, $signedXml);
        }

        return $this->evaluateTokenResult($estado, $token);
    }

    /**
     * Retry exchanging the token with progressive backoff on TokenPending.
     */
    protected function tokenBackoff(SoapClient $client, string $signedXml): string
    {
        foreach (self::RETRY_DELAYS as $delay) {
            $this->sleepFor($delay);

            [$estado, $token] = $this->callToken($client, $signedXml);

            if ($this->state($estado) === SiiAuthState::TokenPending) {
                continue;
            }

            return $this->evaluateTokenResult($estado, $token);
        }

        throw new RuntimeException('SII could not exchange the seed for a token after multiple retries.');
    }

    /**
     * Evaluate a terminal GetTokenFromSeed response state.
     */
    protected function evaluateTokenResult(string $estado, string $token): string
    {
        $state = $this->state($estado);

        if ($state === SiiAuthState::Ok) {
            return $token;
        }

        if ($state !== null && in_array($state, SiiAuthState::clientErrors(), true)) {
            throw new RuntimeException(
                sprintf('SII rejected the token request (code %s): %s', $estado, $state->gloss())
            );
        }

        if ($state === SiiAuthState::CertificateRejected) {
            throw new SiiAuthenticationException(
                'SII rejected the certificate: it is invalid, expired or the RUT is not enrolled.'
            );
        }

        throw new RuntimeException("SII returned an unexpected token state '$estado'.");
    }

    /**
     * Map a raw SII response state to its enum, or null when unknown.
     */
    protected function state(string $estado): ?SiiAuthState
    {
        return SiiAuthState::tryFrom($estado);
    }

    /**
     * Invoke the CrSeed `getSeed` SOAP call and parse its response.
     *
     * @return array{0: string, 1: string}
     */
    protected function callSeed(SoapClient $client): array
    {
        $xml = $this->normalizeSoapReturn($client->getSeed());

        $estado = $this->statusElement($xml, static::ESTADO_ELEMENT);

        if ($estado === null) {
            throw new RuntimeException('Unable to parse the SII seed response.');
        }

        $semilla = $this->state($estado) === SiiAuthState::Ok
            ? ($this->statusElement($xml, 'SEMILLA') ?? '')
            : '';

        return [$estado, $semilla];
    }

    /**
     * Invoke the GetTokenFromSeed `getToken` SOAP call and parse its response.
     *
     * @return array{0: string, 1: string}
     */
    protected function callToken(SoapClient $client, string $signedXml): array
    {
        $xml = $this->normalizeSoapReturn($client->getToken($signedXml));

        $estado = $this->statusElement($xml, static::ESTADO_ELEMENT);

        if ($estado === null) {
            throw new RuntimeException('Unable to parse the SII token response.');
        }

        $token = $this->state($estado) === SiiAuthState::Ok
            ? ($this->statusElement($xml, 'TOKEN') ?? '')
            : '';

        return [$estado, $token];
    }

    /**
     * Build the XMLDSig enveloped-signed `<getToken>` document for a seed.
     */
    protected function buildSignedSeedXml(string $seed, DigitalCertificate $certificate): string
    {
        $doc = $this->xml->document();

        $root = $doc->createElement('getToken');
        $doc->appendChild($root);

        $item = $doc->createElement('item');
        $root->appendChild($item);

        $item->appendChild($doc->createElement('Semilla', $seed));

        $digest = $this->computeSeedDigest($root);

        $pem = $this->openSsl->readPkcs12String($certificate->pkcs12, $certificate->password);

        $signedInfoXml = $this->buildSignedInfoXml($digest);
        $signatureValue = $this->computeSignatureValue($signedInfoXml, $pem[static::PEM_PRIVATE_KEY]);
        [$modulus, $exponent] = $this->extractRsaComponents($pem[static::PEM_PRIVATE_KEY]);
        $x509b64 = $this->parseX509($pem['cert']);

        $signatureXml = $this->buildSignatureXml($signedInfoXml, $signatureValue, $modulus, $exponent, $x509b64);

        $sigDoc = $this->xml->document();
        $sigDoc->loadXML($signatureXml);
        $sigNode = $doc->importNode($sigDoc->documentElement, true);
        $root->appendChild($sigNode);

        return $doc->saveXML();
    }

    /**
     * Compute the SHA-1 digest over the canonicalized unsigned getToken document.
     */
    protected function computeSeedDigest(DOMElement $root): string
    {
        return base64_encode(sha1($root->C14N(false, false), true));
    }

    /**
     * Build the SignedInfo XML string with an enveloped-signature transform.
     */
    protected function buildSignedInfoXml(string $digest): string
    {
        return str_replace('{$digest}', $digest, $this->file->get(__DIR__.'/stubs/xmlsignatureinfo.stub'));
    }

    /**
     * Compute the RSA-SHA1 signature value over the canonicalized SignedInfo.
     */
    protected function computeSignatureValue(string $signedInfoXml, string $privateKey): string
    {
        $siDoc = $this->xml->document();
        $siDoc->loadXML($signedInfoXml);

        return $this->openSsl->sign($siDoc->documentElement->C14N(false, false), $privateKey);
    }

    /**
     * Extract the RSA modulus and exponent from the certificate private key.
     */
    protected function extractRsaComponents(string $privateKey): array
    {
        $details = $this->openSsl->privateKeyDetails($privateKey);
        $rsa = is_array($details) ? ($details['rsa'] ?? null) : null;

        if (!is_array($rsa)) {
            throw new RuntimeException('Unable to extract the certificate RSA public key.');
        }

        return [base64_encode($rsa['n']), base64_encode($rsa['e'])];
    }

    /**
     * Build the full Signature XML string.
     */
    protected function buildSignatureXml(
        string $signedInfoXml,
        string $signatureValue,
        string $modulus,
        string $exponent,
        string $x509b64
    ): string {
        return str_replace(
            ['{$signedInfoXml}', '{$signatureValue}', '{$modulus}', '{$exponent}', '{$x509b64}'],
            [$signedInfoXml, $signatureValue, $modulus, $exponent, $x509b64],
            $this->file->get(__DIR__.'/stubs/xmlsignature.stub')
        );
    }

    /**
     * Extract the base64-encoded X.509 certificate from its PEM representation.
     */
    protected function parseX509(string $certificate): string
    {
        $lines = explode("\n", trim($certificate));
        $b64 = '';

        foreach ($lines as $line) {
            if (!str_contains($line, '-----')) {
                $b64 .= trim($line);
            }
        }

        return $b64;
    }

    /**
     * Normalize the SOAP return value into a usable XML string.
     */
    protected function normalizeSoapReturn(mixed $response): string
    {
        if (is_object($response)) {
            foreach (['getSeedReturn', 'getTokenReturn', 'getSeedResult', 'getTokenResult', 'return'] as $property) {
                if (isset($response->{$property})) {
                    return (string) $response->{$property};
                }
            }

            $response = (string) $response;
        }

        return (string) $response;
    }

    /**
     * Extract the value of an element by local name from an XML string.
     */
    protected function statusElement(string $xml, string $localName): ?string
    {
        try {
            $simple = $this->xml->simpleXml($xml);
        } catch (Throwable) {
            return null;
        }

        $nodes = $simple->xpath("//*[local-name() = '$localName']");

        return isset($nodes[0]) ? trim((string) $nodes[0]) : null;
    }

    /**
     * Build a configured SOAP client for the given WSDL URL.
     */
    protected function newSoapClient(string $wsdlUrl): SoapClient
    {
        return $this->soapProxy
            ->withWsdl($wsdlUrl)
            ->build();
    }

    /**
     * Resolve the WSDL URL for the given environment and service.
     */
    protected function resolveWsdlUrl(?string $baseUrl, string $service): string
    {
        if ($baseUrl === null) {
            throw new RuntimeException('Cannot resolve the WSDL URL without a SOAP base URL.');
        }

        return $baseUrl.'/DTEWS/'.$service.'.jws?WSDL';
    }

    /**
     * Sleep for the given amount of seconds. Extracted to allow test overrides.
     */
    protected function sleepFor(int $seconds): void
    {
        sleep($seconds);
    }
}
