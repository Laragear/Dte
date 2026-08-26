<?php

namespace Tests\Unit\Certification\Interchange\Pipes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Certification\Interchange\Pipes\ProcessInboundDte;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Dte\Services\InboundDteProcessor;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Laravel\Prompts\Note;
use Mockery\MockInterface;
use RuntimeException;
use Tests\DatabaseTestCase;

class ProcessInboundDteTest extends DatabaseTestCase
{
    use InteractsWithPipelines;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Happy Paths
    |--------------------------------------------------------------------------
    */

    public function test_processes_inbound_dte(): void
    {
        Note::fake();

        $emailData = new InboundEmailData(
            messageId: 'found',
            sender: 'sii_dte_intercambio@sii.cl',
            subject: 'Intercambio',
            xmlAttachment: '<xml></xml>'
        );

        $this->mock(InboundDteProcessor::class)->expects('process')->once()->with($emailData);

        $log = SiiInterchangeLog::factory()->create(['message_id' => 'found']);
        $doc = SiiInboundDocument::factory()->create(['sii_interchange_log_id' => $log->id]);

        $data = new InterchangeData(
            new Rut(76_123_456, 0),
            emailData: $emailData
        );

        $this->pipeline(Interchange::class)
            ->isolatePipe(ProcessInboundDte::class)
            ->send($data)
            ->assertPassable(function (InterchangeData $result) use ($doc) {
                static::assertTrue($result->inboundDocument->is($doc));

                return true;
            });

        Note::assertOutputContains('Inbound DTE successfully processed and saved to the database.');
    }

    /*
    |--------------------------------------------------------------------------
    | Sad Paths
    |--------------------------------------------------------------------------
    */

    // No expected user input errors for this pipe.

    /*
    |--------------------------------------------------------------------------
    | Angry Paths
    |--------------------------------------------------------------------------
    */

    public function test_fails_when_processing_throws_exception(): void
    {
        $emailData = new InboundEmailData(
            messageId: 'found',
            sender: 'sii_dte_intercambio@sii.cl',
            subject: 'Intercambio',
            xmlAttachment: '<xml></xml>'
        );

        $this->mock(InboundDteProcessor::class, function (MockInterface $mock) use ($emailData) {
            $mock->expects('process')->once()->with($emailData)->andThrow(new RuntimeException('Processing failed'));
        });

        $data = new InterchangeData(new Rut(76_123_456, 0));
        $data->emailData = $emailData;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Processing failed');

        $this->pipeline(Interchange::class)
            ->isolatePipe(ProcessInboundDte::class)
            ->send($data);
    }
}
