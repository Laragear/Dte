<?php

namespace Laragear\Dte\Certification\Simulation;

use Illuminate\Pipeline\Pipeline;
use Laragear\Rut\Rut;

class Simulation extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\GenerateSimulationDtes::class,
        Pipes\CompileEnvelope::class,
        Pipes\SendEnvelope::class,
    ];

    /**
     * Executes the simulation step for the given RUT.
     */
    public function forRut(Rut $rut): SimulationData
    {
        return $this->send(new SimulationData($rut))->thenReturn();
    }
}
