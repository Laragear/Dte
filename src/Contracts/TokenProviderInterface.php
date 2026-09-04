<?php

namespace Laragear\Dte\Contracts;

use Laragear\Dte\Gateways\Token;
use Laragear\Rut\Rut;

interface TokenProviderInterface
{
    /**
     * Get a valid authentication token for the given taxpayer.
     */
    public function token(Rut $issuer, ?string $baseUrl = null): Token;

    /**
     * Execute a callback, retrying with a freshly authenticated token
     * when the service rejects the current one (SII 001/002/003).
     *
     * @param  callable(): mixed  $request
     */
    public function retryWithFreshToken(callable $request, Rut $issuer): mixed;
}
