<?php

namespace Laragear\Dte\Gateways\Exceptions;

use RuntimeException;

/**
 * Thrown when SII cannot provide a seed after exhausting the retry backoff
 * (CrSeed response code -2: "Reintentar más tarde").
 */
class SiiSeedUnavailableException extends RuntimeException
{
    //
}
