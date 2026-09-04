<?php

namespace Laragear\Dte\Mailbox;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Gateways\SoapClientFactory;
use Laragear\Dte\Gateways\TokenStatus;
use Laragear\Rut\Rut;
use RuntimeException;
use SoapFault;

/**
 * Resolves the official DTE interchange email address for a given RUT,
 * using the SII directory SOAP service with a configurable cache layer.
 *
 * Cache key format: dte|exchange_email|rut:{rut}
 */
class RutEmailResolver
{
    public function __construct(
        protected Cache $cache,
        protected ConfigRepository $config,
        protected DateFactory $date,
        protected TokenProviderInterface $tokenProvider,
        protected SoapClientFactory $soapClientFactory,
        protected EnvironmentResolver $environment,
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
     *
     * Uses the authenticator's retryWithFreshToken() loop: on
     * TokenInvalidException (SII returned 001/002/003), the authenticator
     * refreshes the token and retries — up to 3 total attempts.
     */
    protected function fetchFromSii(Rut $rut): ?string
    {
        $environment = $this->environment->resolve();

        if ($environment->isLocal() || $environment->isTesting()) {
            return null;
        }

        $baseUrl = $environment->isProduction()
            ? 'https://palena.sii.cl'
            : 'https://maullin.sii.cl';

        return $this->tokenProvider->retryWithFreshToken(function () use ($rut, $baseUrl): ?string {
            $token = $this->tokenProvider->token($rut);

            $wsdlUrl = $baseUrl.'/DTEWS/CrSeed.asmx?WSDL';

            $client = $this->soapClientFactory->createAuthenticatedClient($wsdlUrl, $token);

            try {
                $result = $client->__soapCall('getEmailByCodigo', [
                    [
                        'RutEmpresa' => $rut->num,
                        'DvEmpresa' => $rut->vd,
                    ],
                ]);
            } catch (SoapFault) {
                throw new RuntimeException('SII directory service failed to resolve email for '.$rut->formatBasic().'.');
            }

            // SII signals an inactive/invalid token with 001/002/003 in the response header: refresh and retry.
            $estado = $result->ESTADO
                ?? $result->getEmailByCodigoResult->ESTADO
                ?? null;

            if (TokenStatus::isNotValid($estado)) {
                throw new TokenInvalidException('SII directory service rejected the authentication token.');
            }

            $email = (string) ($result->getEmailByCodigoResult->email ?? '');

            return $email !== '' ? $email : null;
        }, $rut);
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
