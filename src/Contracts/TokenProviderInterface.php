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
}
