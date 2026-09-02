<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laragear\Dte\Certification\TestingSet\Pipes\SendTestingEnvelope;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetEnvelope;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Events\EnvelopeSending;
use Laragear\Dte\Events\EnvelopeSent;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\TokenAuthenticator;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;

class SendTestingEnvelopeTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public function test_sends_envelope(): void
    {
        $event = Event::fake([EnvelopeSending::class, EnvelopeSent::class]);

        $envelope = SiiDteEnvelope::factory()->create(['status' => EnvelopeStatus::Pending, 'track_id' => null]);
        $envelope->payload()->create(['xml' => '<xml></xml>']);

        $data = new TestSetData(new Rut(76_123_456, 0));
        $data->envelope = $envelope;

        Http::fake([
            'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload' => Http::response(
                '<RESPONSE><STATUS>0</STATUS><TRACKID>987654</TRACKID></RESPONSE>'
            ),
        ]);

        $this->mock(TokenAuthenticator::class, static function (MockInterface $mock): void {
            $mock->expects('token')->andReturn(new Token('fake', new DateTimeImmutable('+1 hour')));
            $mock->expects('retryWithFreshToken')->andReturnUsing(fn($request, $issuer) => $request());
        });

        $this->app['config']->set('dte.environment', DteEnvironment::Local->value);

        $this
            ->pipeline(TestSetEnvelope::class)
            ->isolatePipe(SendTestingEnvelope::class)
            ->send($data)
            ->assertPassable(function (TestSetData $data) {
                static::assertEquals('987654', $data->envelope->track_id);
                static::assertEquals(EnvelopeStatus::Uploaded, $data->envelope->status);

                return true;
            });

        $event->assertDispatched(EnvelopeSending::class, function ($event) use ($envelope) {
            return $event->envelope->id === $envelope->id;
        });

        $event->assertDispatched(EnvelopeSent::class, function (EnvelopeSent $event) use ($envelope) {
            return
                $event->envelope->id === $envelope->id
                && $event->envelope->track_id === '987654';
        });
    }
}
