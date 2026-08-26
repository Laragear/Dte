<?php

namespace Tests\Unit\Certification\Simulation;

use Laragear\Dte\Certification\Simulation\Pipes\CompileEnvelope;
use Laragear\Dte\Certification\Simulation\Pipes\GenerateSimulationDtes;
use Laragear\Dte\Certification\Simulation\Pipes\SendEnvelope;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class SimulationTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(Simulation::class)->assertPipes([
            GenerateSimulationDtes::class,
            CompileEnvelope::class,
            SendEnvelope::class,
        ]);
    }

    public function test_receives_rut_into_passable(): void
    {
        $pipeline = $this->app->make(Simulation::class);

        $pipeline->through([]);

        $result = $pipeline->forRut(new Rut(76123456, 0));

        static::assertEquals('76.123.456-0', $result->rut);
    }
}
