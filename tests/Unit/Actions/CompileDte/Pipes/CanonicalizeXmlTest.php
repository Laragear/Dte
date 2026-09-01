<?php

namespace Tests\Unit\Actions\CompileDte\Pipes;

use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Actions\CompileDte\Pipes\CanonicalizeXml;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlCanonicalizer;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Mockery\MockInterface;
use RuntimeException;
use Tests\DatabaseTestCase;

class CanonicalizeXmlTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_canonicalizes_and_replaces_document(): void
    {
        $dte = SiiDte::factory()->create();

        $originalDocument = $this->app->make(XmlDomFactory::class)->document();
        $originalDocument->loadXML('<DTE><Documento><Test/></Documento></DTE>');

        $canonicalizedXml = '<DTE><Documento><Canonicalized/></Documento></DTE>';

        $this
            ->mock(XmlCanonicalizer::class)
            ->expects('canonicalize')
            ->once()
            ->with($originalDocument)
            ->andReturn($canonicalizedXml);

        $compilation = new Compilation($dte);
        $compilation->document = $originalDocument;

        $this
            ->pipeline(Compile::class)
            ->isolatePipe(CanonicalizeXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) use ($dte) {
                return
                    $result->dte->is($dte)
                    && $result->document->encoding === 'ISO-8859-1'
                    && str_contains($result->document->saveXML(), '<Canonicalized/>');
            });
    }

    /*
     |--------------------------------------------------------------------------
     | Angry paths
     |--------------------------------------------------------------------------
     */

    public function test_throws_exception_if_xml_cannot_be_parsed(): void
    {
        $dte = SiiDte::factory()->create();

        $originalDocument = $this->app->make(XmlDomFactory::class)->document();
        $originalDocument->loadXML('<DTE><Documento><Test/></Documento></DTE>');

        $invalidXml = '<DTE><Documento><UnclosedTag></Documento></DTE>';

        $this->mock(XmlCanonicalizer::class, function (MockInterface $mock) use ($originalDocument, $invalidXml) {
            $mock
                ->expects('canonicalize')
                ->once()
                ->with($originalDocument)
                ->andReturn($invalidXml);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to parse the canonical DTE XML.');

        $compilation = new Compilation($dte);
        $compilation->document = $originalDocument;

        $this
            ->pipeline(Compile::class)
            ->isolatePipe(CanonicalizeXml::class)
            ->send($compilation);
    }
}
