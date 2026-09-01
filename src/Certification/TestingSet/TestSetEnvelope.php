<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;
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
        CompileEnvelope::class, // Reuse the pipeline from the simulation
        Pipes\SendTestingEnvelope::class,
    ];
}
