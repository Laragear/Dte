<?php

namespace Tests\Unit\Console\Commands;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Models\SiiDteEnvelopePayload;
use Laragear\Dte\Xml\XmlSigner;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;

class ProcessAndSendEnvelopeCommandTest extends DatabaseTestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_processes_and_sends_envelope(): void
    {
        $this->app->make('config')->set('dte.environment', DteEnvironment::Local->value);
        $this->app->make(EnvironmentResolver::class)->flush();

        $envelope = SiiDteEnvelope::factory()->create();

        $this->mock(CreateEnvelope::class, function (MockInterface $mock) use ($envelope): void {
            $mock
                ->expects('send')
                ->withArgs(function (SiiDteEnvelope $sent) use ($envelope): bool {
                    return $sent->is($envelope);
                })
                ->once()
                ->andReturnSelf();

            $mock
                ->expects('thenReturn')
                ->once()
                ->andReturnUsing(function () use ($envelope) {
                    $payload = SiiDteEnvelopePayload::factory()->make(['xml' => 'signed-xml']);
                    $envelope->setRelation('payload', $payload);

                    return new Assembly($envelope);
                });
        });

        $this
            ->artisan('dte:process-envelope', ['envelope_id' => $envelope->getKey()])
            ->expectsOutput(
                "Envelope [{$envelope->getKey()}] processed and sent with Track ID: fake-track-id-{$envelope->getKey()}",
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('sii_dte_envelopes', [
            'id' => $envelope->getKey(),
            'track_id' => "fake-track-id-{$envelope->getKey()}",
        ]);
    }

    public function test_processes_and_sends_boleta_envelope(): void
    {
        $this->app['env'] = 'production';
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $envelope = SiiDteEnvelope::factory()->create(['type' => 'boleta']);

        Http::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => Http::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => Http::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => Http::response(
                ['trackid' => 'boleta-track-id', 'estado' => 'REC', 'codigo' => 0],
                200,
            ),
        ]);

        $this
            ->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->andReturn(new DigitalCertificate('fake', 'fake'));

        $this
            ->mock(XmlSigner::class)
            ->expects('sign')
            ->andReturnUsing(function ($target) {
                return $target;
            });

        $this->mock(CreateEnvelope::class, function (MockInterface $mock) use ($envelope): void {
            $mock
                ->expects('send')
                ->withArgs(fn($s) => $s->is($envelope))
                ->once()
                ->andReturnSelf();
            $mock
                ->expects('thenReturn')
                ->once()
                ->andReturnUsing(function () use ($envelope) {
                    $payload = SiiDteEnvelopePayload::factory()->make(['xml' => 'signed-xml']);
                    $envelope->setRelation('payload', $payload);

                    return new Assembly($envelope);
                });
        });

        $this
            ->artisan('dte:process-envelope', ['envelope_id' => $envelope->getKey()])
            ->expectsOutput("Envelope [{$envelope->getKey()}] processed and sent with Track ID: boleta-track-id")
            ->assertSuccessful();

        $this->assertDatabaseHas('sii_dte_envelopes', [
            'id' => $envelope->getKey(),
            'track_id' => 'boleta-track-id',
        ]);
    }

    /*
     |--------------------------------------------------------------------------
     | Sad paths
     |--------------------------------------------------------------------------
     */

    public function test_fails_when_envelope_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessageIs('No query results for model [Laragear\Dte\Models\SiiDteEnvelope] 999');

        $this->artisan('dte:process-envelope', ['envelope_id' => 999]);
    }
}
