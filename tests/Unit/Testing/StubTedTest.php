<?php

namespace Tests\Unit\Testing;

use DOMDocument;
use Laragear\Dte\Pdf\TedExtractor;
use Tests\TestCase;

class StubTedTest extends TestCase
{
    public function test_stub_ted_xml_is_valid_xml(): void
    {
        $xml = file_get_contents(__DIR__.'/../../stubs/stub_ted.xml');

        static::assertNotEmpty($xml);

        $doc = new DOMDocument();
        static::assertTrue($doc->loadXML($xml));
    }

    public function test_stub_ted_xml_contains_sii_namespace(): void
    {
        $xml = file_get_contents(__DIR__.'/../../stubs/stub_ted.xml');

        static::assertStringContainsString('xmlns="http://www.sii.cl/SiiDte"', $xml);
    }

    public function test_ted_extractor_can_extract_from_stub(): void
    {
        $xml = file_get_contents(__DIR__.'/../../stubs/stub_ted.xml');

        $extractor = $this->app->make(TedExtractor::class);
        $ted = $extractor->extract($xml);

        static::assertStringStartsWith('<TED', $ted);
        static::assertStringContainsString('<RE>76123456-0</RE>', $ted);
        static::assertStringContainsString('<TD>33</TD>', $ted);
        static::assertStringContainsString('<F>1</F>', $ted);
    }
}
