<?php

namespace Tests\Unit\Actions\CompileDte\Pipes;

use DOMDocument;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Actions\CompileDte\Pipes\BuildXml;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDtePayload;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class BuildXmlTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    protected function makeCompilation(array $data = []): Compilation
    {
        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'folio' => 123,
            'document_type' => DteType::Invoice,
        ]);

        $payload = new SiiDtePayload([
            'sii_dte_id' => $dte->id,
            'data' => array_merge([
                'document_type' => 33,
                'issued_on' => '2024-01-15',
                'issuer' => [
                    'rut' => '11111111-1',
                    'legal_name' => 'Test Company',
                    'business_activity' => 'Test Activity',
                    'economic_activity' => '620100',
                ],
                'receiver' => [
                    'rut' => '22222222-2',
                    'legal_name' => 'Client Company',
                ],
                'totals' => [
                    'net' => 10000,
                    'exempt' => 0,
                    'tax' => 1900,
                    'total' => 11900,
                ],
                'items' => [
                    [
                        'name' => 'Item 1',
                        'description' => null,
                        'quantity' => 1,
                        'unit' => null,
                        'unit_price' => 10000,
                        'discount_percentage' => 0,
                        'exempt' => false,
                        'code' => null,
                        'code_type' => null,
                    ],
                ],
                'references' => [],
            ], $data),
        ]);

        $dte->setRelation('payload', $payload);

        return new Compilation($dte);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy paths
    |--------------------------------------------------------------------------
    */

    public function test_build_xml_dom_creates_valid_document(): void
    {
        $compilation = $this->makeCompilation();

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) {
                static::assertInstanceOf(DOMDocument::class, $result->document);
                static::assertEquals('ISO-8859-1', $result->document->encoding);
                static::assertStringContainsString('<DTE', $result->document->saveXML());
                static::assertStringContainsString('<Documento', $result->document->saveXML());

                return true;
            });
    }

    public function test_append_receiver_returns_early_when_receiver_is_null(): void
    {
        // Line 119: return when receiver is null
        $compilation = $this->makeCompilation(['receiver' => null]);

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) {
                static::assertInstanceOf(DOMDocument::class, $result->document);
                $xml = $result->document->saveXML();
                static::assertStringNotContainsString('<Receptor', $xml);

                return true;
            });
    }

    public function test_append_item_code_creates_code_elements_when_code_present(): void
    {
        // Lines 191-193: creates CdgItem, TpoCodigo, VlrCodigo when item has code
        $compilation = $this->makeCompilation([
            'items' => [
                [
                    'name' => 'Item with Code',
                    'description' => null,
                    'quantity' => 1,
                    'unit' => null,
                    'unit_price' => 10000,
                    'discount_percentage' => 0,
                    'exempt' => false,
                    'code' => 'SKU-001',
                    'code_type' => 'INT1',
                ],
            ],
        ]);

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) {
                $xml = $result->document->saveXML();
                static::assertStringContainsString('<CdgItem', $xml);
                static::assertStringContainsString('<TpoCodigo>INT1</TpoCodigo>', $xml);
                static::assertStringContainsString('<VlrCodigo>SKU-001</VlrCodigo>', $xml);

                return true;
            });
    }

    public function test_append_references_creates_reference_elements(): void
    {
        // Lines 231-237: creates Referencia elements with all sub-elements
        $compilation = $this->makeCompilation([
            'references' => [
                [
                    'document_type' => 33,
                    'folio' => 456,
                    'date' => '2024-01-10',
                    'reference_code' => 1,
                    'reason' => 'Replaces invoice 456',
                ],
            ],
        ]);

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) {
                $xml = $result->document->saveXML();
                static::assertStringContainsString('<Referencia', $xml);
                static::assertStringContainsString('<NroLinRef>1</NroLinRef>', $xml);
                static::assertStringContainsString('<TpoDocRef>33</TpoDocRef>', $xml);
                static::assertStringContainsString('<FolioRef>456</FolioRef>', $xml);
                static::assertStringContainsString('<FchRef>2024-01-10</FchRef>', $xml);
                static::assertStringContainsString('<CodRef>1</CodRef>', $xml);
                static::assertStringContainsString('<RazonRef>Replaces invoice 456</RazonRef>', $xml);

                return true;
            });
    }

    public function test_append_references_with_optional_fields_only(): void
    {
        $compilation = $this->makeCompilation([
            'references' => [
                [
                    'document_type' => 33,
                    'folio' => 456,
                    'date' => '2024-01-10',
                    'reference_code' => null,
                    'reason' => null,
                ],
            ],
        ]);

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $result) {
                $xml = $result->document->saveXML();
                static::assertStringContainsString('<Referencia', $xml);
                static::assertStringNotContainsString('<CodRef>', $xml);
                static::assertStringNotContainsString('<RazonRef>', $xml);

                return true;
            });
    }

    public function test_builds_xml_with_transport_and_payment_terms(): void
    {
        $compilation = $this->makeCompilation([
            'payment' => [
                'condition' => 2,
                'expiration_date' => '2023-12-31',
            ],
            'transport' => [
                'vehicle_plate' => 'AA1122',
                'trailer_plate' => 'BB3344',
                'carrier_rut' => '22222222-2',
                'driver_rut' => '33333333-3',
                'driver_name' => 'John Doe',
                'destination_address' => '123 Fake St',
                'destination_commune' => 'Santiago',
                'destination_city' => 'Santiago',
            ],
        ]);

        $this->pipeline(Compile::class)
            ->isolatePipe(BuildXml::class)
            ->send($compilation)
            ->assertPassable(function (Compilation $data) {
                $xml = $data->document->saveXML();

                // Assert Payment Terms
                static::assertStringContainsString('<FmaPago>2</FmaPago>', $xml);
                static::assertStringContainsString('<FchVenc>2023-12-31</FchVenc>', $xml);

                // Assert Transport
                static::assertStringContainsString('<Transporte>', $xml);
                static::assertStringContainsString('<Patente>AA1122</Patente>', $xml);
                static::assertStringContainsString('<PatenteVehiculo>BB3344</PatenteVehiculo>', $xml);
                static::assertStringContainsString('<RUTTrans>22222222-2</RUTTrans>', $xml);
                static::assertStringContainsString('<Chofer>', $xml);
                static::assertStringContainsString('<RUT>33333333-3</RUT>', $xml);
                static::assertStringContainsString('<Nombre>John Doe</Nombre>', $xml);
                static::assertStringContainsString('</Chofer>', $xml);
                static::assertStringContainsString('<DirDest>123 Fake St</DirDest>', $xml);
                static::assertStringContainsString('<CmnaDest>Santiago</CmnaDest>', $xml);
                static::assertStringContainsString('<CiudadDest>Santiago</CiudadDest>', $xml);
                static::assertStringContainsString('</Transporte>', $xml);

                return true;
            });
    }
}
