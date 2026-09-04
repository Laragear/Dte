<?php

namespace Tests\Unit\Certification;

use Laragear\Dte\Certification\IecvBuilder;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\IecvType;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class IecvBuilderTest extends DatabaseTestCase
{
    /*
    |--------------------------------------------------------------------------
    | Happy paths
    |--------------------------------------------------------------------------
    */

    public function test_builds_valid_iecv_sales_xml_against_schema(): void
    {
        $dtes = SiiDte::factory()
            ->count(2)
            ->sequence(
                [
                    'document_type' => DteType::Invoice,
                    'folio' => 1,
                    'issuer_rut' => '76123456-0',
                    'receiver_rut' => '55666777-8',
                    'issued_on' => '2023-10-05',
                    'amount_net' => 1000,
                    'amount_exempt' => 0,
                    'amount_taxes' => 190,
                    'amount_total' => 1190,
                ],
                [
                    'document_type' => DteType::Invoice,
                    'folio' => 2,
                    'issuer_rut' => '76123456-0',
                    'receiver_rut' => '55666777-8',
                    'issued_on' => '2023-10-06',
                    'amount_net' => 2000,
                    'amount_exempt' => 0,
                    'amount_taxes' => 380,
                    'amount_total' => 2380,
                ]
            )
            ->createMany();

        $xml = $this->app->make(IecvBuilder::class)->build(
            dtes: $dtes,
            type: IecvType::Sales,
            period: '2023-10',
            resolutionDate: '2020-01-01',
            resolutionNumber: 1234,
            senderRut: Rut::parse('55666777-8')
        );

        $dom = $this->app->make(XmlDomFactory::class)->document();
        $dom->loadXML($xml);

        // Append dummy signature to pass schema validation
        $signatureXml = static::getStub('FakeIecvXmlSignature.xml');

        $sigDom = $this->app->make(XmlDomFactory::class)->document();
        $sigDom->loadXML($signatureXml);

        $dom->documentElement->appendChild($dom->importNode($sigDom->documentElement, true));

        $schemaPath = static::STUBS.'/LibroCV_v10.xsd';

        static::assertTrue(
            $dom->schemaValidate($schemaPath),
            'The generated IECV XML does not match the EnvioLibro_v10.xsd schema.'
        );

        // Sales book must have FolioNotificacion = 1
        static::assertStringContainsString('<FolioNotificacion>1</FolioNotificacion>', $xml);
        // Tax rate should be derived from config (default 19.00)
        static::assertStringContainsString('<TasaImp>19.00</TasaImp>', $xml);
        // TotMntIVA must always be present (not conditionally omitted)
        static::assertStringContainsString('<TotMntIVA>570</TotMntIVA>', $xml);
    }

    public function test_builds_iecv_purchases_and_amount_exempt(): void
    {
        $dtes = SiiDte::factory()
            ->count(1)
            ->sequence(
                [
                    'document_type' => DteType::Invoice,
                    'folio' => 1,
                    'issuer_rut' => '76123456-0',
                    'receiver_rut' => '55666777-8',
                    'issued_on' => '2023-10-05',
                    'amount_net' => 1000,
                    'amount_exempt' => 500,
                    'amount_taxes' => 190,
                    'amount_total' => 1690,
                ]
            )
            ->createMany();

        $xml = $this->app->make(IecvBuilder::class)->build(
            dtes: $dtes,
            type: IecvType::Purchases,
            period: '2023-10',
            resolutionDate: '2020-01-01',
            resolutionNumber: 1234,
            senderRut: Rut::parse('55666777-8')
        );

        $xmlString = $xml;

        // Should include MntExe
        static::assertStringContainsString('<MntExe>500</MntExe>', $xmlString);
        // For Purchases, RUTDoc should be the issuer
        static::assertStringContainsString('<RUTDoc>76123456-0</RUTDoc>', $xmlString);
        // Purchases book must have FolioNotificacion = 2
        static::assertStringContainsString('<FolioNotificacion>2</FolioNotificacion>', $xmlString);
        // TotMntIVA must always be present (570 here, even though purchases total differs)
        static::assertStringContainsString('<TotMntIVA>190</TotMntIVA>', $xmlString);
        // MntIVA must always be present in Detalle for non-common-use DTEs
        static::assertStringContainsString('<MntIVA>190</MntIVA>', $xmlString);
    }

    public function test_builds_iecv_with_exempt_dte(): void
    {
        $dtes = SiiDte::factory()
            ->count(1)
            ->sequence(
                [
                    'document_type' => DteType::Invoice,
                    'folio' => 1,
                    'issuer_rut' => '76123456-0',
                    'receiver_rut' => '55666777-8',
                    'issued_on' => '2023-10-05',
                    'amount_net' => 0,
                    'amount_exempt' => 1000,
                    'amount_taxes' => 0,
                    'amount_total' => 1000,
                ]
            )
            ->createMany();

        $xml = $this->app->make(IecvBuilder::class)->build(
            dtes: $dtes,
            type: IecvType::Sales,
            period: '2023-10',
            resolutionDate: '2020-01-01',
            resolutionNumber: 1234,
            senderRut: Rut::parse('55666777-8')
        );

        // Exempt DTEs must have TasaImp = 0.00
        static::assertStringContainsString('<TasaImp>0.00</TasaImp>', $xml);
        // MntIVA must always be present, even when 0
        static::assertStringContainsString('<MntIVA>0</MntIVA>', $xml);
        // TotMntIVA must be present in ResumenPeriodo, even when 0
        static::assertStringContainsString('<TotMntIVA>0</TotMntIVA>', $xml);
    }
}
