<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Certification\Pipes\EnsureDtesCompiled;
use Laragear\Dte\Certification\Simulation\Pipes\CompileEnvelope;

class TestSetEnvelope extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\RetrievePendingSiiDte::class,
        EnsureDtesCompiled::class,
        Pipes\ValidateTestSetReferences::class,
        CompileEnvelope::class,
        Pipes\SendTestingEnvelope::class,
    ];
}
