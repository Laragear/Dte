<?php

namespace Tests\Unit\Certification\Pipes;

use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Caf\CafParser;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Certification\Pipes\EnsureDtesCompiled;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetEnvelope;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiCaf;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDtePayload;
use Laragear\Dte\Xml\TimbreSigner;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Dte\Xml\XmlValidator;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class EnsureDtesCompiledTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    private const VALID_CAF_XML = '<AUTORIZACION><CAF><DA><RE>76123456-0</RE></DA></CAF></AUTORIZACION>';

    private function createPayloadData(): array
    {
        return [
            'document_type' => DteType::Invoice->value,
            'issued_on' => '2024-01-15',
            'issuer' => [
                'rut' => '76.123.456-0',
                'legal_name' => 'Test Company',
                'business_activity' => 'Software',
                'economic_activity' => ['620100'],
                'address' => 'Test Address 123',
                'commune' => 'Santiago',
                'city' => 'Santiago',
                'resolution_date' => '2023-01-01',
                'resolution_number' => 12345,
            ],
            'receiver' => [
                'rut' => '11.222.333-4',
                'legal_name' => 'Client Company',
                'business_activity' => 'Services',
                'address' => 'Client Address',
                'commune' => 'Santiago',
                'city' => 'Santiago',
            ],
            'totals' => [
                'net' => 10000,
                'exempt' => 0,
                'tax' => 1900,
                'total' => 11900,
            ],
            'items' => [
                [
                    'name' => 'Test Item',
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
        ];
    }

    private function mockCompilationDependencies(): void
    {
        $this->mock(CafParser::class)->allows('parse')->andReturn([
            'private_key' => 'fake_private_key',
            'xml' => self::VALID_CAF_XML,
        ]);

        $this->mock(TimbreSigner::class)->allows('sign')->andReturn('fake_signature');

        $certificate = new DigitalCertificate('fake_path', 'fake_password');
        $this->mock(CertificateResolverInterface::class)->allows('resolve')->andReturn($certificate);

        $this->mock(XmlSigner::class)->allows('sign');

        $this->mock(XmlValidator::class)->allows('verifySignature');
    }

    private function createDteWithPayload(?string $xml = null): SiiDte
    {
        $this->mockCompilationDependencies();

        $caf = SiiCaf::factory()->create([
            'rut' => new Rut(76_123_456, 0),
            'document_type' => DteType::Invoice,
            'xml' => self::VALID_CAF_XML,
        ]);

        $dte = SiiDte::factory()->create([
            'issuer_rut' => $caf->rut,
            'document_type' => $caf->document_type,
            'sii_caf_id' => $caf->id,
            'folio' => $caf->folio_from,
        ]);

        $dte->setRelation('caf', $caf);

        SiiDtePayload::factory()->create([
            'sii_dte_id' => $dte->id,
            'data' => $this->createPayloadData(),
            'xml' => $xml,
        ]);

        return $dte->load('payload');
    }

    public function test_compiles_dtes_with_null_xml(): void
    {
        $dte = $this->createDteWithPayload(xml: null);

        $data = new SimulationData(new Rut(76_123_456, 0));
        $data->dtes = SiiDte::whereKey($dte->id)->get();

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(EnsureDtesCompiled::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) {
                static::assertNotNull($data->dtes->first()->payload);
                static::assertNotNull($data->dtes->first()->payload->xml);

                return true;
            });
    }

    public function test_skips_already_compiled_dtes(): void
    {
        $xml = '<DTE><Documento ID="test"></Documento></DTE>';
        $dte = $this->createDteWithPayload(xml: $xml);

        $data = new SimulationData(new Rut(76_123_456, 0));
        $data->dtes = SiiDte::whereKey($dte->id)->get();

        $compileMock = $this->mock(Compile::class);
        $compileMock->expects('forDte')->never();

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(EnsureDtesCompiled::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) use ($xml) {
                static::assertSame($xml, $data->dtes->first()->payload->xml);

                return true;
            });
    }

    public function test_compiles_only_missing_dtes_in_mixed_batch(): void
    {
        $xml = '<DTE><Documento ID="test"></Documento></DTE>';
        $dte1 = $this->createDteWithPayload(xml: $xml);
        $dte2 = $this->createDteWithPayload(xml: null);

        $data = new SimulationData(new Rut(76_123_456, 0));
        $data->dtes = SiiDte::whereKey([$dte1->id, $dte2->id])->get();

        $this
            ->pipeline(Simulation::class)
            ->isolatePipe(EnsureDtesCompiled::class)
            ->send($data)
            ->assertPassable(function (SimulationData $data) use ($xml) {
                $result = $data->dtes->keyBy('id');

                static::assertSame($xml, $result->first()->payload->xml);
                static::assertNotNull($result->last()->payload->xml);

                return true;
            });
    }

    public function test_works_with_test_set_data(): void
    {
        $dte = $this->createDteWithPayload(xml: null);

        $data = new TestSetData(new Rut(76_123_456, 0));
        $data->dtes = SiiDte::whereKey($dte->id)->get();

        $this
            ->pipeline(TestSetEnvelope::class)
            ->isolatePipe(EnsureDtesCompiled::class)
            ->send($data)
            ->assertPassable(function (TestSetData $data) {
                static::assertNotNull($data->dtes->first()->payload);
                static::assertNotNull($data->dtes->first()->payload->xml);

                return true;
            });
    }
}
