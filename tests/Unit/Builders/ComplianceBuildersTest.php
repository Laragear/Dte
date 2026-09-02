<?php

namespace Tests\Unit\Builders;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use Laragear\Dte\Builders\CommercialReceiptBuilder;
use Laragear\Dte\Builders\XmlResponseBuilder;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Facades\Generator;
use Tests\TestCase;
use Tests\Unit\Certificate\Fixtures\CertificateFixture;
use function str_replace;

class ComplianceBuildersTest extends TestCase
{
    protected DigitalCertificate $certificate;

    protected CertificateFixture $fixture;

    protected SiiInboundDocument $dte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = CertificateFixture::create();

        $this->certificate = new DigitalCertificate(file_get_contents($this->fixture->path), $this->fixture->password);
        $this->dte = $this->document(DteType::Invoice);
    }

    protected function tearDown(): void
    {
        $this->fixture->delete();

        parent::tearDown();
    }

    public function test_builds_a_schema_valid_signed_formato_ic_response(): void
    {
        $xml = $this->app->make(XmlResponseBuilder::class)->forDocument(
            $this->dte,
            $this->dte->receiver_rut,
            10,
            20,
            0,
            'ACEPTADO OK',
            $this->certificate,
            new DateTimeImmutable('2026-08-13 12:00:00'),
        );
        $document = $this->xml($xml);

        static::assertTrue($document->schemaValidateSource($this->responseSchema()));
        static::assertSame('33', $this->value($document, '//sii:ResultadoDTE/sii:TipoDTE'));
        static::assertSame('ACEPTADO OK', $this->value($document, '//sii:EstadoDTEGlosa'));
        static::assertSame(1, $this->xpath($document)->query('//ds:Signature')?->length);
    }

    public function test_builds_a_schema_valid_signed_formato_ic_response_with_reason_code(): void
    {
        $xml = $this->app->make(XmlResponseBuilder::class)->forDocument(
            $this->dte,
            $this->dte->receiver_rut,
            10,
            20,
            2,
            'RECHAZADO',
            $this->certificate,
            new DateTimeImmutable('2026-08-13 12:00:00'),
            -1,
        );
        $document = $this->xml($xml);

        static::assertTrue($document->schemaValidateSource($this->responseSchema()));
        static::assertSame('33', $this->value($document, '//sii:ResultadoDTE/sii:TipoDTE'));
        static::assertSame('RECHAZADO', $this->value($document, '//sii:EstadoDTEGlosa'));
        static::assertSame('-1', $this->value($document, '//sii:CodRchDsc'));
        static::assertSame(1, $this->xpath($document)->query('//ds:Signature')?->length);
    }

    public function test_builds_a_schema_valid_signed_commercial_receipt(): void
    {
        $xml = $this->app->make(CommercialReceiptBuilder::class)->build(
            $this->dte,
            $this->dte->receiver_rut,
            'Main Warehouse',
            $this->certificate,
            new DateTimeImmutable('2026-08-13 12:00:00'),
        );
        $document = $this->xml($xml);

        static::assertTrue($document->schemaValidate(static::STUBS.'/EnvioRecibos_v10.xsd'));
        static::assertSame(CommercialReceiptBuilder::DECLARATION, $this->value($document, '//sii:Declaracion'));
        static::assertSame(2, $this->xpath($document)->query('//ds:Signature')?->length);
    }

    public function test_commercial_receipts_reject_unsupported_document_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The DTE type does not support a Ley 19.983 commercial receipt.');

        $this->app->make(CommercialReceiptBuilder::class)->build(
            $this->document(DteType::CreditNote),
            Generator::asPeople()->makeOne(),
            'Main Warehouse',
            $this->certificate,
        );
    }

    protected function document(DteType $type): SiiInboundDocument
    {
        return new SiiInboundDocument([
            'issuer_rut' => Generator::asCompanies()->makeOne()->formatRaw(),
            'receiver_rut' => Generator::asCompanies()->makeOne()->formatRaw(),
            'document_type' => $type,
            'folio' => 123,
            'issued_on' => '2026-08-01',
            'amount_total' => 1190,
        ]);
    }

    protected function xml(string $xml): DOMDocument
    {
        $document = $this->app->make(XmlDomFactory::class)->document();
        static::assertTrue($document->loadXML($xml, LIBXML_NONET));

        return $document;
    }

    protected function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = $this->app->make(XmlDomFactory::class)->xpath($document);
        $xpath->registerNamespace('sii', XmlDomFactory::XML_NAMESPACE);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return $xpath;
    }

    protected function value(DOMDocument $document, string $query): string
    {
        return $this->xpath($document)->query($query)?->item(0)?->textContent ?? '';
    }

    protected function responseSchema(): string
    {
        $schema = static::getStub('RespuestaEnvioDTE_v10.xsd');

        // We require setting the absolute path of the schemas for the XSD so it can validate.
        return (string) str_replace(
            ['SiiTypes_v10.xsd', 'xmldsignature_v10.xsd'],
            [static::STUBS.'/SiiTypes_v10.xsd', static::STUBS.'/xmldsignature_v10.xsd'],
            $schema ?: '',
        );
    }
}
