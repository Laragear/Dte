<?php

namespace Tests\Unit\Certification\Simulation\Pipes;

use Laragear\Dte\Certification\Simulation\Pipes\GenerateSimulationDtes;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class GenerateSimulationDtesTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    public function test_generates_dtes_with_default_options(): void
    {
        $data = new SimulationData(new Rut(76_123_456, 0), quantity: 10, documentTypes: []);

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(GenerateSimulationDtes::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) {
                static::assertCount(10, $data->dtes);
                $this->assertDatabaseCount('sii_dtes', 10);

                $data->dtes->each(function ($dte) {
                    static::assertEquals('76123456-0', $dte->issuer_rut->formatBasic());
                });

                return true;
            });
    }

    public function test_generates_dtes_with_custom_quantity_and_types(): void
    {
        $data = new SimulationData(new Rut(76_123_456, 0), quantity: 15, documentTypes: [39, 41]);

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(GenerateSimulationDtes::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) {
                static::assertCount(15, $data->dtes);
                $this->assertDatabaseCount('sii_dtes', 15);

                $data->dtes->each(function ($dte) {
                    static::assertContains($dte->document_type->value, [39, 41]);
                });

                return true;
            });
    }
}
