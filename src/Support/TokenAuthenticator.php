<?php

namespace Laragear\Dte\Support;

use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Enums\TokenType;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Gateways\RestAuthGateway;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Gateways\Token;
use Laragear\Rut\Rut;
use Throwable;

/**
 * Owns SII authentication and the token cache.
 *
 * This is the only component that authenticates against SII and the only
 * caller of TokenRepository. It is also the single authority for the token
 * TTL: both gateways return raw credential strings, and this class decides
 * the expiration and wraps them into a Token.
 */
class TokenAuthenticator implements TokenProviderInterface
{
    /**
     * Create a new Token Authenticator instance.
     */
    public function __construct(
        protected TokenRepository $repository,
        protected SoapGateway $soap,
        protected RestAuthGateway $rest,
    ) {
        //
    }

    /**
     * Get a valid SOAP authentication token for the given taxpayer.
     *
     * Returns the cached token if still valid, otherwise authenticates with
     * SII and caches the new token.
     */
    public function token(Rut $issuer, ?string $baseUrl = null): Token
    {
        $cached = $this->repository->get(TokenType::Soap, $issuer);

        if ($cached !== null) {
            return $cached;
        }

        return $this->authenticateSoap($issuer, $baseUrl);
    }

    /**
     * Get a valid REST authentication token value for the given taxpayer.
     *
     * Returns the cached token if still valid, otherwise authenticates with
     * SII and caches the new token.
     */
    public function restToken(Rut $issuer, ?string $authUrl = null): string
    {
        $cached = $this->repository->get(TokenType::Rest, $issuer);

        if ($cached !== null) {
            return $cached->value;
        }

        return $this->authenticateRest($issuer, $authUrl);
    }

    /**
     * Force re-authentication for the SOAP endpoint.
     *
     * Invalidates the cached SOAP token and retrieves + caches a fresh one.
     * Called by consumers when SII signals the current token is no longer
     * valid.
     */
    public function refresh(Rut $issuer, ?string $baseUrl = null): Token
    {
        $this->repository->forget(TokenType::Soap, $issuer);

        return $this->authenticateSoap($issuer, $baseUrl);
    }

    /**
     * Force re-authentication for the REST endpoint.
     *
     * Invalidates the cached REST token and retrieves + caches a fresh one.
     */
    public function refreshRest(Rut $issuer, ?string $authUrl = null): string
    {
        $this->repository->forget(TokenType::Rest, $issuer);

        return $this->authenticateRest($issuer, $authUrl);
    }

    /**
     * Perform the SOAP authentication flow and cache the resulting token.
     */
    protected function authenticateSoap(Rut $issuer, ?string $baseUrl): Token
    {
        // Validate the TTL before any SII round-trip so operator
        // misconfiguration fails fast.
        $ttl = $this->repository->ttl(TokenType::Soap);

        $raw = $this->soap->authenticate($issuer, null, $baseUrl);

        $token = Token::fromString($raw, $ttl);

        $this->repository->put(TokenType::Soap, $issuer, $token);

        return $token;
    }

    /**
     * Perform the REST authentication flow and cache the resulting token.
     */
    protected function authenticateRest(Rut $issuer, ?string $authUrl): string
    {
        $ttl = $this->repository->ttl(TokenType::Rest);

        $raw = $this->rest->fetchToken($issuer, $authUrl);

        $token = Token::fromString($raw, $ttl);

        $this->repository->put(TokenType::Rest, $issuer, $token);

        return $token->value;
    }

    /**
     * Execute a callback, retrying with a freshly authenticated SOAP token
     * when SII rejects the current one.
     *
     * Retries only on TokenInvalidException (refreshing the SOAP token before
     * each subsequent attempt); any other exception is rethrown immediately.
     * After 3 total attempts, the last TokenInvalidException is rethrown.
     *
     * @param  callable(): mixed  $request  The SII request to execute.
     */
    public function retryWithFreshToken(callable $request, Rut $issuer): mixed
    {
        return $this->retryLoop($request, $issuer, fn(Rut $rut) => $this->refresh($rut));
    }

    /**
     * Execute a callback, retrying with a freshly authenticated REST token
     * when SII rejects the current one.
     *
     * Retries only on TokenInvalidException (refreshing the REST token before
     * each subsequent attempt); any other exception is rethrown immediately.
     * After 3 total attempts, the last TokenInvalidException is rethrown.
     *
     * @param  callable(): mixed  $request  The SII request to execute.
     */
    public function retryRestWithFreshToken(callable $request, Rut $issuer): mixed
    {
        return $this->retryLoop($request, $issuer, fn(Rut $rut) => $this->refreshRest($rut));
    }

    /**
     * Shared refresh-and-retry loop for the retry*WithFreshToken methods.
     *
     * @param  callable(): mixed  $request  The SII request to execute.
     * @param  callable(Rut): void  $refresh  Refreshes the token to retry with.
     */
    private function retryLoop(callable $request, Rut $issuer, callable $refresh): mixed
    {
        return retry(3, $request, when: function (Throwable $e) use ($issuer, $refresh): bool {
            if ($e instanceof TokenInvalidException) {
                $refresh($issuer);

                return true;
            }

            return false;
        });
    }
}
