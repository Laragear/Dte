<?php

namespace Tests\Unit\Actions\CreateEnvelope\Pipes;

use DOMDocument;
use InvalidArgumentException;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Actions\CreateEnvelope\Pipes\EmbedDteNodes;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Models\SiiDtePayload;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Mockery;
use RuntimeException;
use Tests\DatabaseTestCase;

class EmbedDteNodesTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    protected function makeAssemblyWithDtes(int $count, string $xml = '<DTE><Documento></Documento></DTE>'): Assembly
    {
        $envelope = SiiDteEnvelope::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            $dte = SiiDte::factory()->create([
                'sii_dte_envelope_id' => $envelope->id,
            ]);

            SiiDtePayload::factory()->create([
                'sii_dte_id' => $dte->id,
                'xml' => $xml,
            ]);
        }

        $assembly = new Assembly($envelope);

        $writer = $this->app->make(XmlDomFactory::class)->writer();
        $writer->openMemory();
        $writer->startDocument('1.0', 'ISO-8859-1');
        $writer->startElement('EnvioDTE');
        $writer->startElement('SetDTE');
        $assembly->writer = $writer;

        return $assembly;
    }

    public function test_embeds_nodes_and_closes_envelope(): void
    {
        $assembly = $this->makeAssemblyWithDtes(2);
        $assembly->expectedDocuments = 2;

        $this->pipeline(CreateEnvelope::class)
            ->isolatePipe(EmbedDteNodes::class)
            ->send($assembly)
            ->assertPassable(function (Assembly $result) {
                static::assertEquals(2, $result->embeddedDocuments);
                static::assertNull($result->writer); // closed

                return true;
            });
    }

    public function test_throws_if_xml_payload_is_malformed(): void
    {
        $assembly = $this->makeAssemblyWithDtes(1);

        $document = Mockery::mock(DOMDocument::class);
        $document->expects('loadXml')->andReturnFalse();

        $this->mock(XmlDomFactory::class)->expects('document')->andReturn($document);


        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The model XML payload is malformed.');

        $this->pipeline(CreateEnvelope::class)
            ->isolatePipe(EmbedDteNodes::class)
            ->send($assembly);
    }

    public function test_throws_if_embedded_count_does_not_match_expected(): void
    {
        $assembly = $this->makeAssemblyWithDtes(1);
        $assembly->expectedDocuments = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Every envelope document must contain a signed XML payload.');

        $this->pipeline(CreateEnvelope::class)
            ->isolatePipe(EmbedDteNodes::class)
            ->send($assembly);
    }

    public function test_throws_if_payload_does_not_contain_dte_root(): void
    {
        $assembly = $this->makeAssemblyWithDtes(1, '<WrongRoot></WrongRoot>');
        $assembly->expectedDocuments = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('An envelope payload does not contain a DTE root element.');

        $this->pipeline(CreateEnvelope::class)
            ->isolatePipe(EmbedDteNodes::class)
            ->send($assembly);
    }
}
