<?php

namespace Laragear\Dte\Mailbox;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Rut\Rut;
use RuntimeException;
use SoapFault;
use SoapHeader;

/**
 * Resolves the official DTE interchange email address for a given RUT,
 * using the SII directory SOAP service with a configurable cache layer.
 *
 * Cache key format: dte|exchange_email|rut:{rut}
 */
final readonly class RutEmailResolver
{
    public function __construct(
        protected Cache $cache,
        protected ConfigRepository $config,
        protected DateFactory $date,
        protected TokenProviderInterface $tokens,
        protected SoapProxy $soapProxy,
    ) {
        //
    }

    /**
     * Resolve the DTE interchange email for the given RUT.
     *
     * Will return a cached result if available and caching is enabled.
     */
    public function resolve(Rut $rut): ?string
    {
        $cacheEnabled = $this->config->get('dte.dim.addresses.cache', true);
        $cacheKey = $this->cacheKey($rut);

        if ($cacheEnabled) {
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        $email = $this->fetchFromSii($rut);

        if ($cacheEnabled && $email !== null) {
            $days = $this->config->get('dte.dim.addresses.days', 30);
            $this->cache->put($cacheKey, $email, $this->date->now()->addDays($days));
        }

        return $email;
    }

    /**
     * Fetch the exchange email from the SII SOAP directory service.
     */
    protected function fetchFromSii(Rut $rut): ?string
    {
        $token = $this->tokens->token($rut);
        $environment = $this->config->get('dte.environment', 'local');

        if ($environment === 'local' || $environment === 'testing') {
            return null;
        }

        $baseUrl = $environment === 'production'
            ? 'https://palena.sii.cl'
            : 'https://maullin.sii.cl';

        $wsdlUrl = $baseUrl.'/DTEWS/CrSeed.asmx?WSDL';

        $client = $this->soapProxy
            ->withWsdl($wsdlUrl)
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->build();

        $header = new SoapHeader('http://www.sii.cl/ws/', 'Token', $token->value);
        $client->__setSoapHeaders($header);

        try {
            $result = $client->__soapCall('getEmailByCodigo', [
                [
                    'RutEmpresa' => $rut->num,
                    'DvEmpresa' => $rut->vd,
                ],
            ]);

            $email = (string) ($result->getEmailByCodigoResult->email ?? '');

            return $email !== '' ? $email : null;
        } catch (SoapFault) {
            throw new RuntimeException('SII directory service failed to resolve email for '.$rut->formatBasic().'.');
        }
    }

    /**
     * Build the globally prefixed cache key for this RUT's exchange email.
     */
    protected function cacheKey(Rut $rut): string
    {
        $prefix = $this->config->get('dte.cache.prefix', 'dte');

        return $prefix.'|exchange_email|rut:'.$rut->formatRaw();
    }
}
