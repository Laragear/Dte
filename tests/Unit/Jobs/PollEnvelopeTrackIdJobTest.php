<?php

namespace Tests\Unit\Jobs;

use DOMElement;
use Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Events\DteAccepted;
use Laragear\Dte\Events\DteRejected;
use Laragear\Dte\Events\EnvelopeAccepted;
use Laragear\Dte\Events\EnvelopeRejected;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Jobs\PollDteStatusJob;
use Laragear\Dte\Jobs\PollEnvelopeTrackIdJob;
use Laragear\Dte\Jobs\SendInterchangeEnvelopeJob;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Xml\XmlSigner;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;
use Tests\Unit\Certificate\Fixtures\CertificateFixture;

class PollEnvelopeTrackIdJobTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();
    }

    public function test_accepts_envelope_when_epr_and_dispatches_event(): void
    {
        $event = Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andReturn(new Token('FAKE_TOKEN', now()->addHour()->toDateTimeImmutable()));
            $mock->expects('query')
                ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '12345')
                ->andReturn('<ESTADO>EPR</ESTADO>');
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Accepted, $envelope->fresh()->status);
        static::assertNotNull($envelope->fresh()->accepted_at);

        $event->assertDispatched(EnvelopeAccepted::class, function ($event) use ($envelope) {
            return $event->envelope->id === $envelope->id;
        });

        $event->assertNotDispatched(EnvelopeRejected::class);
        $event->assertNotDispatched(DteRejected::class);
    }

    public function test_accepts_boleta_envelope_when_epr_and_dispatches_event(): void
    {
        $event = Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'type' => 'boleta',
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
            'type' => 'boleta',
        ]);

        Http::fake([
            '*/boleta.electronica.semilla' => Http::response('<RESP_BODY><SEMILLA>123</SEMILLA></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.token' => Http::response('<RESP_BODY><TOKEN>FAKE_TOKEN</TOKEN></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.envio/*' => Http::response(['estado' => 'EPR'], 200),
        ]);

        $this->mock(CertificateResolverInterface::class, function ($mock) {
            $fixture = CertificateFixture::create();
            $cert = new DigitalCertificate(file_get_contents($fixture->path),
                $fixture->password);
            $mock->expects('resolve')->andReturn($cert);
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Accepted, $envelope->fresh()->status);
        static::assertNotNull($envelope->fresh()->accepted_at);
        $event->assertDispatched(EnvelopeAccepted::class);
    }

    public function test_rejects_boleta_envelope_when_rejected_status_and_dispatches_events(): void
    {
        $event = Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
            'type' => 'boleta',
        ]);

        Http::fake([
            '*/boleta.electronica.semilla' => Http::response('<RESP_BODY><SEMILLA>123</SEMILLA></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.token' => Http::response('<RESP_BODY><TOKEN>FAKE_TOKEN</TOKEN></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.envio/*' => Http::response(['estado' => 'RCH'], 200),
        ]);

        $this->mock(CertificateResolverInterface::class, function ($mock) {
            $fixture = CertificateFixture::create();
            $cert = new DigitalCertificate(file_get_contents($fixture->path),
                $fixture->password);
            $mock->expects('resolve')->andReturn($cert);
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Rejected, $envelope->fresh()->status);
        static::assertNotNull($envelope->fresh()->rejected_at);
        $event->assertDispatched(EnvelopeRejected::class);
    }

    public function test_keeps_boleta_envelope_uploaded_when_processing(): void
    {
        $event = Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
            'type' => 'boleta',
        ]);

        Http::fake([
            '*/boleta.electronica.semilla' => Http::response('<RESP_BODY><SEMILLA>123</SEMILLA></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.token' => Http::response('<RESP_BODY><TOKEN>FAKE_TOKEN</TOKEN></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.envio/*' => Http::response(['estado' => 'REC'], 200),
        ]);

        $this->mock(CertificateResolverInterface::class, function ($mock) {
            $fixture = CertificateFixture::create();
            $cert = new DigitalCertificate(file_get_contents($fixture->path),
                $fixture->password);
            $mock->expects('resolve')->andReturn($cert);
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Uploaded, $envelope->fresh()->status);
        $event->assertNotDispatched(EnvelopeAccepted::class);
        $event->assertNotDispatched(EnvelopeRejected::class);
    }

    public function test_logs_warning_on_unknown_status_for_boleta(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => 'UNKNOWN_TRACK',
            'type' => 'boleta',
        ]);

        Http::fake([
            '*/boleta.electronica.semilla' => Http::response('<RESP_BODY><SEMILLA>12345</SEMILLA></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.token' => Http::response('<RESP_BODY><TOKEN>FAKE_TOKEN</TOKEN></RESP_BODY>',
                200, ['Content-Type' => 'application/xml']),
            '*/boleta.electronica.envio/*' => Http::response(['estado' => 'UNKNOWN'], 200),
        ]);

        $this->mock(CertificateResolverInterface::class, function ($mock) {
            $fixture = CertificateFixture::create();
            $cert = new DigitalCertificate(file_get_contents($fixture->path),
                $fixture->password);
            $mock->expects('resolve')->andReturn($cert);
        });

        Log::shouldReceive('error')->andReturnUsing(function ($msg) {
            dump($msg);
        });
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) use ($envelope) {
                return str_contains($msg,
                    "Unknown SII boleta track ID status received for track ID {$envelope->track_id}:");
            });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Uploaded, $envelope->fresh()->status);
    }

    public function test_keeps_envelope_uploaded_when_processing_and_no_events(): void
    {
        Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '55555',
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andReturn(new Token('FAKE_TOKEN', now()->addHour()->toDateTimeImmutable()));
            $mock->expects('query')
                ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '55555')
                ->andReturn('<ESTADO>PRD</ESTADO>');
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Uploaded, $envelope->fresh()->status);
        static::assertNull($envelope->fresh()->accepted_at);

        Event::assertNothingDispatched();
    }

    public function test_does_nothing_if_status_is_not_uploaded(): void
    {
        Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Pending,
            'track_id' => '12345',
        ]);

        $this->mock(SoapGateway::class)->shouldNotReceive('token');
        $this->mock(SoapGateway::class)->shouldNotReceive('query');

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Pending, $envelope->fresh()->status);

        Event::assertNothingDispatched();
    }

    public function test_logs_warning_on_unknown_status(): void
    {
        Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => 'UNKNOWN_TRACK',
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andReturn(new Token('FAKE_TOKEN', now()->addHour()->toDateTimeImmutable()));
            $mock->expects('query')
                ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === 'UNKNOWN_TRACK')
                ->andReturn('<ESTADO>UNKNOWN</ESTADO>');
        });

        Log::shouldReceive('warning')
            ->once()
            ->with("Unknown SII track ID status received for track ID {$envelope->track_id}: <ESTADO>UNKNOWN</ESTADO>");

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Uploaded, $envelope->fresh()->status);
    }

    public function test_rejects_envelope_when_rejected_status_and_dispatches_events(): void
    {
        Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '98765',
        ]);

        $dte1 = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'status' => DteStatus::Signed,
        ]);
        $dte2 = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'status' => DteStatus::Signed,
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andReturn(new Token('FAKE_TOKEN', now()->addHour()->toDateTimeImmutable()));
            $mock->expects('query')
                ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '98765')
                ->andReturn('<ESTADO>RCH</ESTADO>');
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(EnvelopeStatus::Rejected, $envelope->fresh()->status);
        static::assertNotNull($envelope->fresh()->rejected_at);

        static::assertEquals(DteStatus::Signed, $dte1->fresh()->status);
        static::assertNull($dte1->fresh()->sii_dte_envelope_id);
        static::assertEquals(1, $dte1->fresh()->pack_retries);

        static::assertEquals(DteStatus::Signed, $dte2->fresh()->status);
        static::assertNull($dte2->fresh()->sii_dte_envelope_id);
        static::assertEquals(1, $dte2->fresh()->pack_retries);

        Event::assertDispatched(EnvelopeRejected::class, function ($event) use ($envelope) {
            return $event->envelope->id === $envelope->id;
        });

        Event::assertNotDispatched(DteRejected::class);
    }

    public function test_rejects_envelope_and_permanently_rejects_dtes_if_max_retries_reached(): void
    {
        Event::fake([EnvelopeAccepted::class, EnvelopeRejected::class, DteRejected::class]);

        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '98765',
        ]);

        $dte1 = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'status' => DteStatus::Signed, 'pack_retries' => 3,
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andReturn(new Token('FAKE_TOKEN', now()->addHour()->toDateTimeImmutable()));
            $mock->expects('query')
                ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '98765')
                ->andReturn('<ESTADO>RCH</ESTADO>');
        });

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        static::assertEquals(DteStatus::Rejected, $dte1->fresh()->status);
        static::assertNotNull($dte1->fresh()->rejected_at);
        static::assertEquals(3, $dte1->fresh()->pack_retries);

        Event::assertDispatched(DteRejected::class);
    }

    public function test_logs_error_and_throws_exception_on_failure(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => 'ERROR_TRACK',
        ]);

        $this->mock(SoapGateway::class, static function (MockInterface $mock) use ($envelope): void {
            $mock->expects('token')
                ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                ->andThrow(new Exception('Token error'));
        });

        Log::shouldReceive('error')
            ->once()
            ->with("Failed to poll TrackID {$envelope->track_id}: Token error");

        $job = new PollEnvelopeTrackIdJob($envelope);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Token error');

        $this->app->call($job->handle(...));
    }

    /*
     |--------------------------------------------------------------------------
     | Additional Envelope Inner DTE processing logic
     |--------------------------------------------------------------------------
     */

    public function test_accepts_boleta_envelope_and_accepts_inner_dtes_when_no_rejections(): void
    {
        $this->app->make('config')->set('dte.dim.auto_send_interchange', false);
        $this->app['env'] = 'production';
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $envelope = SiiDteEnvelope::factory()->create([
            'type' => 'boleta',
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
        ]);
        $dte = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'document_type' => DteType::Receipt,
            'status' => DteStatus::Pending,
        ]);

        Http::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => Http::response('<RESPUESTA><RESP_BODY><SEMILLA>123</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => Http::response('<RESPUESTA><RESP_BODY><TOKEN>tok</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio*' => Http::response([
                'estado' => 'EPR',
                'glosa' => 'Envio Procesado',
                'estadistica' => [['rechazados' => 0, 'reparos' => 0]],
            ]),
        ]);

        $this->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn(new DigitalCertificate('fake', 'fake'));

        $this->mock(XmlSigner::class)
            ->expects('sign')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (DOMElement $target) {
                $target->setAttribute('signed', 'true');

                return $target;
            });

        Event::fake([
            EnvelopeAccepted::class, DteAccepted::class,
        ]);
        Queue::fake([SendInterchangeEnvelopeJob::class]);

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        $dte->refresh();
        static::assertEquals(DteStatus::Accepted, $dte->status);
        Event::assertDispatched(DteAccepted::class);
    }

    public function test_accepts_boleta_envelope_and_dispatches_poll_for_inner_dtes_when_rejections_present(): void
    {
        $this->app->make('config')->set('dte.dim.auto_send_interchange', false);
        $this->app['env'] = 'production';
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $envelope = SiiDteEnvelope::factory()->create([
            'type' => 'boleta',
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
        ]);
        $dte = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'document_type' => DteType::Receipt,
            'status' => DteStatus::Pending,
        ]);

        Http::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => Http::response('<RESPUESTA><RESP_BODY><SEMILLA>123</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => Http::response('<RESPUESTA><RESP_BODY><TOKEN>tok</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio*' => Http::response([
                'estado' => 'EPR',
                'glosa' => 'Envio Procesado',
                'estadistica' => [['rechazados' => 1, 'reparos' => 0]],
            ]),
        ]);

        $this->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn(new DigitalCertificate('fake', 'fake'));

        $this->mock(XmlSigner::class)
            ->expects('sign')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (DOMElement $target) {
                $target->setAttribute('signed', 'true');

                return $target;
            });

        Queue::fake([
            PollDteStatusJob::class, SendInterchangeEnvelopeJob::class,
        ]);

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        Queue::assertPushed(PollDteStatusJob::class);
    }

    public function test_accepts_normal_envelope_and_accepts_inner_dtes_when_no_rejections(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
        ]);
        $dte = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'document_type' => DteType::Invoice,
            'status' => DteStatus::Pending,
        ]);

        $this->mock(SoapGateway::class,
            static function (MockInterface $mock) use ($envelope): void {
                $mock->expects('token')
                    ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                    ->andReturn(new Token('FAKE_TOKEN',
                        now()->addHour()->toDateTimeImmutable()));
                $mock->expects('query')
                    ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '12345')
                    ->andReturn('<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><ESTADO>EPR</ESTADO><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS></SII:RESP_BODY></SII:RESPUESTA>');
            });

        Event::fake([
            EnvelopeAccepted::class, DteAccepted::class,
        ]);
        Queue::fake([SendInterchangeEnvelopeJob::class]);

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        $dte->refresh();
        static::assertEquals(DteStatus::Accepted, $dte->status);
        Event::assertDispatched(DteAccepted::class);
    }

    public function test_accepts_normal_envelope_and_dispatches_poll_for_inner_dtes_when_rejections_present(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '12345',
        ]);
        $dte = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'document_type' => DteType::Invoice,
            'status' => DteStatus::Pending,
        ]);

        Queue::fake([PollDteStatusJob::class]);

        $this->mock(SoapGateway::class,
            static function (MockInterface $mock) use ($envelope): void {
                $mock->expects('token')
                    ->withArgs(fn ($rut) => $rut->num === $envelope->issuer_rut->num)
                    ->andReturn(new Token('FAKE_TOKEN',
                        now()->addHour()->toDateTimeImmutable()));
                $mock->expects('query')
                    ->withArgs(fn ($rut, $service, $action, $args) => $args['TrackId'] === '12345')
                    ->andReturn('<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><ESTADO>EPR</ESTADO><RECHAZADOS>1</RECHAZADOS><REPAROS>0</REPAROS></SII:RESP_BODY></SII:RESPUESTA>');
            });

        Queue::fake([
            PollDteStatusJob::class, SendInterchangeEnvelopeJob::class,
        ]);

        $job = new PollEnvelopeTrackIdJob($envelope);
        $this->app->call($job->handle(...));

        Queue::assertPushed(PollDteStatusJob::class);
    }
}
