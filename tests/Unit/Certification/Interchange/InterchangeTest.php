<?php

namespace Tests\Unit\Certification\Interchange;

use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\Pipes\AcceptAndSendReceipt;
use Laragear\Dte\Certification\Interchange\Pipes\FetchInterchangeXml;
use Laragear\Dte\Certification\Interchange\Pipes\ProcessInboundDte;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class InterchangeTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(Interchange::class)->assertPipes([
            FetchInterchangeXml::class,
            ProcessInboundDte::class,
            AcceptAndSendReceipt::class,
        ]);
    }

    public function test_receives_rut_into_passable(): void
    {
        $pipeline = $this->app->make(Interchange::class);

        $pipeline->through([]);

        $result = $pipeline->forRut(new Rut(76123456, 0));

        static::assertEquals('76.123.456-0', $result->rut);
    }
}
