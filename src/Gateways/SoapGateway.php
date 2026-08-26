<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Rut\Rut;
use RuntimeException;
use SoapHeader;

use function assert;

/**
 * SOAP Gateway for communicating with SII legacy Web Services.
 *
 * Handles Seed/Token authentication with caching to prevent SII rate limiting.
 * Cache key format: dte|soap_token|business:{rut}
 */
class SoapGateway implements TokenProviderInterface
{
    /**
     * Create a new SOAP Gateway instance.
     */
    public function __construct(
        protected CertificateResolverInterface $certificates,
        protected EnvironmentResolver $environment,
        protected Repository $cache,
        protected ConfigRepository $config,
        protected SoapProxy $soapProxy,
        protected OpenSslProxy $openSsl,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Get a valid authentication token for the given taxpayer.
     *
     * Will retrieve from cache if available, otherwise authenticate with SII.
     */
    public function token(Rut $issuer, ?string $baseUrl = null): Token
    {
        $cacheKey = $this->cacheKey($issuer);

        /** @var Token|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached?->isNotExpired()) {
            return $cached;
        }

        $certificate = $this->certificates->resolve($issuer);

        if ($certificate === null) {
            throw new RuntimeException('No digital certificate resolved for issuer '.$issuer->formatBasic());
        }

        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();

        if ($baseUrl === null) {
            throw new RuntimeException('Cannot authenticate because no SOAP Base URL is available in this environment.');
        }

        return $this->authenticate($issuer, $certificate, $baseUrl);
    }

    /**
     * Authenticate with SII to get a new token.
     */
    public function authenticate(Rut $issuer, DigitalCertificate $certificate, ?string $baseUrl = null): Token
    {
        $wsdlUrl = $this->resolveWsdlUrl($baseUrl, 'wsDTECorreo');

        $client = $this->soapProxy
            ->withWsdl($wsdlUrl)
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->build();

        // 1. Get Seed from SII
        $seed = (string) $client->getSeed()->getSeedResult;

        // 2. Sign the seed with the certificate private key
        $pem = $this->openSsl->readPkcs12String($certificate->pkcs12, $certificate->password);
        $signedSeed = $this->openSsl->sign($seed, $pem['pkey']);

        // 3. Get Token from SII using the signed seed
        $token = (string) $client->getToken($signedSeed)->getTokenResult;

        // Parse token expiration (typically ~12 hours / 43.200 seconds from SII)
        $expiresAt = $this->date->now('America/Santiago')->addHours(12)->toDateTimeImmutable();

        $tokenObject = new Token($token, $expiresAt);

        // Cache the token with the prefixed key
        $this->cache->put($this->cacheKey($issuer), $tokenObject, $expiresAt);

        return $tokenObject;
    }

    /**
     * Execute a generic SOAP query against the specified service.
     */
    public function query(Rut $issuer, string $service, string $action, array $arguments = [], ?string $baseUrl = null): mixed
    {
        $token = $this->token($issuer, $baseUrl);
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();
        $wsdlUrl = $this->resolveWsdlUrl($baseUrl, $service);

        $client = $this->soapProxy
            ->withWsdl($wsdlUrl)
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->build();

        // Add authentication header
        $client->__setSoapHeaders(new SoapHeader(
            'http://www.sii.cl/ws/',
            'Token',
            $token->value,
        ));

        return $client->__soapCall($action, $arguments);
    }

    /**
     * Resolve the WSDL URL for the given environment and service.
     */
    protected function resolveWsdlUrl(?string $baseUrl, string $service): string
    {
        assert($baseUrl !== null);

        return $baseUrl.'/DTEWS/'.$service.'.asmx?WSDL';
    }

    /**
     * Generate the cache key for the token.
     */
    protected function cacheKey(Rut $rut): string
    {
        $prefix = $this->config->get('dte.cache.prefix', 'dte');

        return $prefix.'|soap_token|business:'.$rut->formatRaw();
    }
}
