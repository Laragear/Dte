<?php

namespace Tests\Unit\Actions\InboundDte;

use Laragear\Dte\Actions\InboundDte\Pipes\CreateInterchangeLog;
use Laragear\Dte\Actions\InboundDte\Pipes\ParseInboundDocument;
use Laragear\Dte\Actions\InboundDte\Pipes\ProcessEnvioDteDocuments;
use Laragear\Dte\Actions\InboundDte\Pipes\ProcessRespuestaDteDocuments;
use Laragear\Dte\Actions\InboundDte\Pipes\ValidateXmlStructure;
use Laragear\Dte\Actions\InboundDte\ProcessInboundDte;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\DatabaseTestCase;

class ProcessInboundDteTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    public function test_pipeline_order(): void
    {
        $this->pipeline(ProcessInboundDte::class)
            ->assertPipes([
                ValidateXmlStructure::class,
                ParseInboundDocument::class,
                CreateInterchangeLog::class,
                ProcessEnvioDteDocuments::class,
                ProcessRespuestaDteDocuments::class,
            ]);
    }

    public function test_receives_rut_into_passable(): void
    {
        $pipeline = $this->app->make(ProcessInboundDte::class);

        $pipeline->through([]);

        $email = new InboundEmailData('test', 'test', 'test', 'text-xml');

        $result = $pipeline->forEmail($email);

        static::assertEquals($email, $result->email);
    }
}
