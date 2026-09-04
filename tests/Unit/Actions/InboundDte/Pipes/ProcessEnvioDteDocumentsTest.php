<?php

namespace Tests\Unit\Actions\InboundDte\Pipes;

use Laragear\Dte\Actions\InboundDte\InboundDteData;
use Laragear\Dte\Actions\InboundDte\Pipes\ProcessEnvioDteDocuments;
use Laragear\Dte\Actions\InboundDte\ProcessInboundDte;
use Laragear\Dte\Contracts\TenantResolverInterface;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use RuntimeException;
use Tests\DatabaseTestCase;

class ProcessEnvioDteDocumentsTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    public function test_skips_non_envio_dte(): void
    {
        $this->mock(TenantResolverInterface::class);

        $data = new InboundDteData(
            new InboundEmailData('msg-1', 'a@b.cl', 'Subject', '<RespuestaDTE></RespuestaDTE>'),
            rootName: 'RespuestaDTE',
        );

        $data->log = SiiInterchangeLog::factory()->create();

        $this
            ->pipeline(ProcessInboundDte::class)
            ->isolatePipe(ProcessEnvioDteDocuments::class)
            ->send($data)
            ->assertPassable(function (InboundDteData $result) {
                return $result->rootName === 'RespuestaDTE';
            });
    }

    public function test_throws_when_rut_receptor_missing(): void
    {
        $xmlString = '<EnvioDTE><SetDTE><Caratula></Caratula></SetDTE></EnvioDTE>';

        $this->mock(TenantResolverInterface::class);

        $data = new InboundDteData(
            new InboundEmailData('msg-1', 'a@b.cl', 'Subject', $xmlString),
            xml: $this->app->make(XmlDomFactory::class)->simpleXml($xmlString),
            rootName: 'EnvioDTE',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Missing RutReceptor in EnvioDTE.');

        $this
            ->pipeline(ProcessInboundDte::class)
            ->isolatePipe(ProcessEnvioDteDocuments::class)
            ->send($data)
            ->assertPassable(fn() => false);
    }
}
