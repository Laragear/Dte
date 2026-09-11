<?php

namespace Laragear\Dte\Gateways;

/**
 * Shadow the global sleep() function to prevent actual sleeping in tests.
 */
function sleep(int $seconds): void
{
    // PHP resolves function calls in the current namespace first, then falls back
    // to the global namespace. By defining sleep() here, SoapGateway::sleepFor()
    // will call this instead of the real func. No-op: prevents sleep on test.
}
