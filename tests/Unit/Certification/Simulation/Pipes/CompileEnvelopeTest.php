<?php

namespace Tests\Unit\Certification\Simulation\Pipes;

use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Certification\Simulation\Pipes\CompileEnvelope;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Data\CompanyData;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Mockery\MockInterface;
use Override;
use Tests\DatabaseTestCase;

class CompileEnvelopeTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        ConfigurationManager::setCompany(fn() => CompanyData::make(
            IssuerData::make(
                '76.123.456-0',
                'Test Company',
                'Software',
                ['620100'],
                'Test Address 123',
                'Santiago',
                '2025-01-01',
                76000,
                'Santiago',
                '+56212345678',
                'test@example.com',
                'Casa Matriz',
            ),
            '76.123.456-0',
        ));
    }

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public function test_compiles_envelope(): void
    {
        $this->app->make('config')->set('dte.issuer.resolution_date', '2023-01-01');

        $dtes = SiiDte::factory()->count(10)->create(['issuer_rut' => '76123456-0']);

        $data = new SimulationData(new Rut(76_123_456, 0));
        $data->dtes = $dtes;

        $envelopeMock = SiiDteEnvelope::factory()->make();

        $this->mock(CreateEnvelope::class, function (MockInterface $mock) use ($envelopeMock) {
            $mock
                ->expects('forEnvelope')
                ->withAnyArgs()
                ->once()
                ->andReturn(new Assembly($envelopeMock));
        });

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(CompileEnvelope::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) use ($envelopeMock) {
                static::assertSame($envelopeMock, $data->envelope);
                $this->assertDatabaseCount('sii_dte_envelopes', 1);

                $envelope = SiiDteEnvelope::first();

                static::assertEquals('76123456-0', $envelope->issuer_rut->formatBasic());

                foreach ($data->dtes as $dte) {
                    static::assertEquals($envelope->id, $dte->sii_dte_envelope_id);
                }

                return true;
            });
    }
}
