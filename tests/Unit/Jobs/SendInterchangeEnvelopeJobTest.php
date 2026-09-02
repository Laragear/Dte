<?php

namespace Tests\Unit\Jobs;

use DateTimeImmutable;
use DOMDocument;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Jobs\SendInterchangeEnvelopeJob;
use Laragear\Dte\Mail\Interchange\InterchangeEnvelopeMail;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Tests\DatabaseTestCase as TestCase;

class SendInterchangeEnvelopeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_splits_dtes_by_receiver_and_dispatches_mail(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        $dte1 = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => DteType::Invoice,
        ]);
        $dte2 = SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '77123456-9', 'document_type' => DteType::Invoice,
        ]);

        // Inject cache so the resolver returns test@example.com
        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->put('dte|exchange_email|rut:761234560',
            'test@example.com');
        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->put('dte|exchange_email|rut:771234569',
            'test@example.com');

        $this->mock(TokenProviderInterface::class)->shouldReceive('token')->andReturn(new Token('test',
            new DateTimeImmutable));
        $this->mock(CreateEnvelope::class, function (MockInterface $mock) {
            $assembly1 = Mockery::mock(Assembly::class);
            $doc1 = Mockery::mock(DOMDocument::class);
            $doc1->expects('saveXML')->andReturn('<xml>1</xml>');
            $assembly1->expects('requireDocument')->andReturn($doc1);

            $assembly2 = Mockery::mock(Assembly::class);
            $doc2 = Mockery::mock(DOMDocument::class);
            $doc2->expects('saveXML')->andReturn('<xml>2</xml>');
            $assembly2->expects('requireDocument')->andReturn($doc2);

            $mock->expects('forSharing')->times(2)->andReturn($assembly1, $assembly2);
        });

        Mail::fake();

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));

        Mail::assertSent(InterchangeEnvelopeMail::class, 2);
        Mail::assertSent(InterchangeEnvelopeMail::class, function (InterchangeEnvelopeMail $mail): bool {
            return $mail->hasTo('test@example.com') && $mail->xml !== '';
        });
    }

    public function test_sad_path_skips_when_dim_email_not_resolved(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => DteType::Invoice,
        ]);

        // Don't cache anything, testing environment returns null
        $this->mock(TokenProviderInterface::class)->shouldReceive('token')->andReturn(new Token('test',
            new DateTimeImmutable));
        $this->mock(CreateEnvelope::class)->expects('forSharing')->never();

        Mail::fake();

        $this->mock(LoggerInterface::class)->expects('warning')->once();

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));

        Mail::assertNothingSent();
    }

    public function test_sad_path_gracefully_skips_envelopes_containing_only_boletas(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => 39,
        ]); // Boleta

        $this->mock(TokenProviderInterface::class)->shouldReceive('token')->andReturn(new Token('test',
            new DateTimeImmutable));
        $this->mock(CreateEnvelope::class);

        Mail::fake();

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));

        Mail::assertNothingSent();
    }

    public function test_angry_path_aborts_when_creator_fails_compilation(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => DteType::Invoice,
        ]);

        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->put('dte|exchange_email|rut:761234560',
            'test@example.com');
        $this->mock(TokenProviderInterface::class)->shouldReceive('token')->andReturn(new Token('test',
            new DateTimeImmutable));
        $this->mock(CreateEnvelope::class)->expects('forSharing')->once()->andThrow(new RuntimeException('Compilation Failed'));

        Mail::fake();

        $this->mock(LoggerInterface::class)->expects('error')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Compilation Failed');

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));
    }

    public function test_angry_path_aborts_when_mail_sending_throws_exception(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => DteType::Invoice,
        ]);

        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->put('dte|exchange_email|rut:761234560',
            'test@example.com');
        $this->mock(TokenProviderInterface::class)->shouldReceive('token')->andReturn(new Token('test',
            new DateTimeImmutable));
        $this->mock(CreateEnvelope::class, function (MockInterface $mock) {
            $assembly = Mockery::mock(Assembly::class);
            $doc = Mockery::mock(DOMDocument::class);
            $doc->expects('saveXML')->andReturn('<xml>3</xml>');
            $assembly->expects('requireDocument')->andReturn($doc);
            $mock->expects('forSharing')->once()->andReturn($assembly);
        });

        $pendingMail = $this->mock(PendingMail::class, static function (MockInterface $mock): void {
            $mock->expects('send')->once()->andThrow(new RuntimeException('Network error'));
        });

        $mailer = $this->mock(Mailer::class, static function (MockInterface $mock) use ($pendingMail): void {
            $mock->expects('to')->once()->andReturn($pendingMail);
        });

        $factory = $this->mock(Factory::class, static function (MockInterface $mock) use ($mailer): void {
            $mock->expects('mailer')->once()->andReturn($mailer);
        });

        $this->app->instance(Factory::class, $factory);

        $this->mock(LoggerInterface::class)->expects('error')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Network error');

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));
    }

    public function test_angry_path_aborts_when_envelope_xml_is_empty(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        SiiDte::factory()->create([
            'sii_dte_envelope_id' => $envelope->id, 'receiver_rut' => '76123456-0', 'document_type' => DteType::Invoice,
        ]);

        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->put('dte|exchange_email|rut:761234560',
            'test@example.com');
        $this->mock(TokenProviderInterface::class)
            ->shouldReceive('token')
            ->andReturn(new Token('test', new DateTimeImmutable));

        $this->mock(CreateEnvelope::class, function (MockInterface $mock) {
            $assembly = Mockery::mock(Assembly::class);
            $doc = Mockery::mock(DOMDocument::class);
            $doc->expects('saveXML')->andReturn('');
            $assembly->expects('requireDocument')->andReturn($doc);
            $mock->expects('forSharing')->once()->andReturn($assembly);
        });

        Mail::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to cast ephemeral envelope to XML string.');

        $job = new SendInterchangeEnvelopeJob($envelope);
        $this->app->call($job->handle(...));
    }

    public function test_job_has_tries_backoff_and_timeout_attributes(): void
    {
        $reflection = new ReflectionClass(SendInterchangeEnvelopeJob::class);

        $backoff = $reflection->getAttributes(Backoff::class)[0]->newInstance()->backoff;
        $tries = $reflection->getAttributes(Tries::class)[0]->newInstance()->tries;
        $timeout = $reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout;

        static::assertSame([60, 120, 300], $backoff);
        static::assertSame(3, $tries);
        static::assertSame(120, $timeout);
    }
}
