<?php

namespace Tests\Unit\Certification;

use Laragear\Dte\Certification\IecvBuilder;
use Laragear\Dte\Certification\IecvType;
use Laragear\Dte\Enums\DteType;
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
            type: IecvType::Ventas,
            period: '2023-10',
            resolutionDate: '2020-01-01',
            resolutionNumber: 1234,
            senderRut: Rut::parse('55666777-8')
        );

        $dom = $this->app->make(XmlDomFactory::class)->document();
        $dom->loadXML($xml);

        // Append dummy signature to pass schema validation
        $signatureXml = <<<'XML'
<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
    <SignedInfo>
        <CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
        <SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>
        <Reference URI="#LibroVENTA_202310">
            <Transforms>
                <Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
            </Transforms>
            <DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>
            <DigestValue>ZHVtbXk=</DigestValue>
        </Reference>
    </SignedInfo>
    <SignatureValue>ZHVtbXk=</SignatureValue>
    <KeyInfo>
        <KeyValue>
            <RSAKeyValue>
                <Modulus>ZHVtbXk=</Modulus>
                <Exponent>ZHVtbXk=</Exponent>
            </RSAKeyValue>
        </KeyValue>
        <X509Data>
            <X509Certificate>ZHVtbXk=</X509Certificate>
        </X509Data>
    </KeyInfo>
</Signature>
XML;
        $sigDom = $this->app->make(XmlDomFactory::class)->document();
        $sigDom->loadXML($signatureXml);

        $dom->documentElement->appendChild($dom->importNode($sigDom->documentElement, true));

        $schemaPath = static::STUBS.'/LibroCV_v10.xsd';

        static::assertTrue(
            $dom->schemaValidate($schemaPath),
            'The generated IECV XML does not match the EnvioLibro_v10.xsd schema.'
        );
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
            type: IecvType::Compras,
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
    }
}
