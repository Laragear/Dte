<?php

namespace Laragear\Dte\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Laragear\Dte\Enums\TokenType;
use Laragear\Dte\Gateways\Token;
use Laragear\Rut\Rut;
use function is_numeric;

/**
 * Pure cache mechanics for SII authentication tokens.
 *
 * This is the only component that knows how to build cache keys, compute the
 * TTL, and read/store/touch/forget tokens. Only the TokenAuthenticator calls
 * this — neither gateways nor consumers touch the token cache directly.
 *
 * Cache key format: {prefix}|{service}_token|business:{rut_raw}, where
 * {service} comes from the TokenType enum ('soap' or 'rest').
 */
final class TokenRepository
{
    /**
     * Maximum token lifetime allowed by SII (1 hour).
     */
    protected const int SII_MAX_TTL = 3600;

    /**
     * Create a new Token Repository instance.
     */
    public function __construct(
        protected Repository $cache,
        protected ConfigRepository $config,
    ) {
        //
    }

    /**
     * Resolve the TTL in seconds for the given service, capped at 3600.
     *
     * Both SOAP and REST tokens share the same SII 1-hour lifetime, so both
     * services read the same `dte.soap.token_ttl` configuration.
     */
    public function ttl(TokenType $service): int
    {
        $configured = $this->config->get('dte.soap.token_ttl');

        $ttl = is_numeric($configured) ? (int) $configured : self::SII_MAX_TTL;

        if ($ttl > self::SII_MAX_TTL) {
            throw new InvalidArgumentException(
                strtoupper($service->name)." token TTL must not exceed ".self::SII_MAX_TTL." seconds (SII token lifetime). Got: {$ttl}"
            );
        }

        return $ttl;
    }

    /**
     * Build the cache key for the given service and issuer.
     */
    public function key(TokenType $service, Rut $rut): string
    {
        $prefix = $this->config->get('dte.cache.prefix', 'dte');

        return $prefix.'|'.$service->value.'_token|business:'.$rut->formatRaw();
    }

    /**
     * Retrieve a non-expired token from the cache, or null if absent/expired.
     *
     * Also touches the cache entry to extend the TTL, since SII renews the
     * token lifetime on each use.
     */
    public function get(TokenType $service, Rut $rut): ?Token
    {
        $key = $this->key($service, $rut);

        /** @var Token|null $cached */
        $cached = $this->cache->get($key);

        if ($cached?->isNotExpired()) {
            $this->cache->touch($key, $this->ttl($service));

            return $cached;
        }

        return null;
    }

    /**
     * Store a token in the cache for the given service and issuer.
     */
    public function put(TokenType $service, Rut $rut, Token $token): void
    {
        $this->cache->put($this->key($service, $rut), $token, $token->expiresAt);
    }

    /**
     * Forget the cached token for the given service and issuer.
     */
    public function forget(TokenType $service, Rut $rut): void
    {
        $this->cache->forget($this->key($service, $rut));
    }
}
