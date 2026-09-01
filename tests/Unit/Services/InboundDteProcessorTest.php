<?php

namespace Tests\Unit\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laragear\Dte\Contracts\TenantResolverInterface;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Events\InboundDteReceived;
use Laragear\Dte\Events\InboundForgedDteReceived;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Dte\Services\InboundDteProcessor;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlValidator;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use SoapClient;
use stdClass;
use Tests\DatabaseTestCase;

class InboundDteProcessorTest extends DatabaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(XmlValidator::class)->expects('validate')->andReturn($this->app->make(XmlDomFactory::class)->document());
    }

    public function test_processes_envio_dte_and_detects_authentic_document(): void
    {
        $xmlString = <<<'XML'
            <EnvioDTE>
                <SetDTE>
                    <Caratula>
                        <RutReceptor>76123456-7</RutReceptor>
                    </Caratula>
                    <DTE>
                        <Documento>
                            <Encabezado>
                                <IdDoc>
                                    <TipoDTE>33</TipoDTE>
                                    <Folio>1001</Folio>
                                    <FchEmis>2023-10-01</FchEmis>
                                </IdDoc>
                                <Emisor>
                                    <RUTEmisor>77123456-7</RUTEmisor>
                                </Emisor>
                                <Totales>
                                    <MntTotal>11900</MntTotal>
                                </Totales>
                            </Encabezado>
                        </Documento>
                    </DTE>
                </SetDTE>
            </EnvioDTE>
            XML;

        $emailData = new InboundEmailData(
            'msg-123',
            'vendor@example.com',
            'Envio DTE 1001',
            $xmlString,
        );

        $token = new Token('sii-token', new DateTimeImmutable('+1 hour'));
        $this->app->make(Repository::class)->put('dte|soap_token|business:761234567', $token);

        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $mockResponse = new stdClass;
        $mockResponse->getEstDteAvResult = <<<'XML'
            <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
                <SII:RESP_BODY>
                    <CODIGO_ESTADO>0</CODIGO_ESTADO>
                </SII:RESP_BODY>
            </SII:RESPUESTA>
            XML;

        $tenant = clone $mockResponse;

        $this->mock(TenantResolverInterface::class, static function (MockInterface $mock) use ($tenant): void {
            $mock->expects('resolve')->andReturn($tenant);
        });

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockResponse): void {
            $soapClient = Mockery::mock(SoapClient::class);
            $soapClient->expects('__setSoapHeaders');
            $soapClient
                ->expects('__soapCall')
                ->withArgs(function ($action, $args) {
                    return $action === 'getEstDteAv' && $args['RutEmisor'] === 77123456 && $args['Folio'] === '1001';
                })
                ->andReturn($mockResponse);

            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('withOptions')->andReturnSelf();
            $mock->expects('build')->andReturn($soapClient);
        });

        Event::fake();

        $processor = $this->app->make(InboundDteProcessor::class);
        $processor->process($emailData);

        $this->assertDatabaseHas(SiiInterchangeLog::class, [
            'message_id' => 'msg-123',
            'recipient' => '76123456-7',
        ]);

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'issuer_num' => 77123456,
            'issuer_vd' => '7',
            'receiver_num' => 76123456,
            'receiver_vd' => '7',
            'document_type' => 33,
            'folio' => 1001,
            'amount_total' => 11900,
            'status' => InboundDteStatus::Received,
        ]);

        Event::assertDispatched(InboundDteReceived::class, function ($e) use ($tenant) {
            return $e->tenant === $tenant && $e->document->folio === 1001;
        });
    }

    public function test_processes_respuesta_dte_and_updates_outbound_status(): void
    {
        $dte = SiiDte::factory()->create([
            'document_type' => DteType::Invoice,
            'issuer_rut' => '76123456-0',
            'folio' => 500,
            'accepted_at' => null,
            'rejected_at' => null,
            'acknowledged_at' => null,
        ]);

        $xmlString = <<<'XML'
            <RespuestaDTE>
                <Resultado>
                    <Caratula>
                        <RutResponde>77123456-9</RutResponde>
                        <RutRecibe>76123456-0</RutRecibe>
                    </Caratula>
                    <ResultadoDTE>
                        <TipoDTE>33</TipoDTE>
                        <Folio>500</Folio>
                        <EstadoDTE>0</EstadoDTE>
                    </ResultadoDTE>
                </Resultado>
            </RespuestaDTE>
            XML;

        $emailData = new InboundEmailData('msg-124', 'a@b.cl', 'test', $xmlString);

        $this->mock(TenantResolverInterface::class, static function (MockInterface $mock): void {
            // Not explicitly called for Response processing if there's no cross-logic,
            // but we ensure it's not missing.
        });

        $processor = $this->app->make(InboundDteProcessor::class);
        $processor->process($emailData);

        $dte->refresh();

        static::assertNotNull($dte->accepted_at);
        static::assertNotNull($dte->acknowledged_at);
        static::assertNull($dte->rejected_at);
    }

    public function test_marks_as_forged_on_ws_error(): void
    {
        $xmlString = <<<'XML'
            <EnvioDTE>
                <SetDTE>
                    <Caratula>
                        <RutReceptor>76123456-7</RutReceptor>
                    </Caratula>
                    <DTE>
                        <Documento>
                            <Encabezado>
                                <IdDoc>
                                    <TipoDTE>33</TipoDTE>
                                    <Folio>1003</Folio>
                                    <FchEmis>2023-10-01</FchEmis>
                                </IdDoc>
                                <Emisor>
                                    <RUTEmisor>77123456-7</RUTEmisor>
                                </Emisor>
                                <Totales>
                                    <MntTotal>11900</MntTotal>
                                </Totales>
                            </Encabezado>
                        </Documento>
                    </DTE>
                </SetDTE>
            </EnvioDTE>
            XML;

        $emailData = new InboundEmailData('msg-128', 'a@b.cl', 'test', $xmlString);

        $token = new Token('sii-token', new DateTimeImmutable('+1 hour'));
        $this->app->make(Repository::class)->put('dte|soap_token|business:761234567', $token);

        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $tenant = new stdClass;
        $this->mock(TenantResolverInterface::class, static function (MockInterface $mock) use ($tenant): void {
            $mock->expects('resolve')->andReturn($tenant);
        });

        $this->mock(SoapProxy::class, static function (MockInterface $mock): void {
            $soapClient = Mockery::mock(SoapClient::class);
            $soapClient->expects('__setSoapHeaders');
            $soapClient->expects('__soapCall')->andThrow(new RuntimeException('WS Error'));

            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('withOptions')->andReturnSelf();
            $mock->expects('build')->andReturn($soapClient);
        });

        Event::fake();

        $processor = $this->app->make(InboundDteProcessor::class);
        $processor->process($emailData);

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'folio' => 1003,
            'status' => InboundDteStatus::Forged,
        ]);

        Event::assertDispatched(InboundForgedDteReceived::class, function ($e) use ($tenant) {
            return $e->tenant === $tenant && $e->document->folio === 1003;
        });
    }

    public function test_processes_respuesta_dte_and_updates_rejected_status(): void
    {
        $dte = SiiDte::factory()->create([
            'document_type' => DteType::Invoice,
            'issuer_rut' => '76123456-0',
            'folio' => 501,
            'accepted_at' => null,
            'rejected_at' => null,
            'acknowledged_at' => null,
        ]);

        $xmlString = <<<'XML'
            <RespuestaDTE>
                <Resultado>
                    <Caratula>
                        <RutResponde>77123456-9</RutResponde>
                        <RutRecibe>76123456-0</RutRecibe>
                    </Caratula>
                    <ResultadoDTE>
                        <TipoDTE>33</TipoDTE>
                        <Folio>501</Folio>
                        <EstadoDTE>1</EstadoDTE>
                    </ResultadoDTE>
                </Resultado>
            </RespuestaDTE>
            XML;

        $emailData = new InboundEmailData('msg-129', 'a@b.cl', 'test', $xmlString);

        $this->mock(TenantResolverInterface::class);
        $processor = $this->app->make(InboundDteProcessor::class);
        $processor->process($emailData);

        $dte->refresh();

        static::assertNull($dte->accepted_at);
        static::assertNotNull($dte->acknowledged_at);
        static::assertNotNull($dte->rejected_at);
    }

    public function test_marks_document_as_forged_if_sii_rejects(): void
    {
        $xmlString = <<<'XML'
            <EnvioDTE>
                <SetDTE>
                    <Caratula>
                        <RutReceptor>76123456-7</RutReceptor>
                    </Caratula>
                    <DTE>
                        <Documento>
                            <Encabezado>
                                <IdDoc>
                                    <TipoDTE>33</TipoDTE>
                                    <Folio>1002</Folio>
                                    <FchEmis>2023-10-01</FchEmis>
                                </IdDoc>
                                <Emisor>
                                    <RUTEmisor>77123456-7</RUTEmisor>
                                </Emisor>
                                <Totales>
                                    <MntTotal>11900</MntTotal>
                                </Totales>
                            </Encabezado>
                        </Documento>
                    </DTE>
                </SetDTE>
            </EnvioDTE>
            XML;

        $emailData = new InboundEmailData('msg-123', 'a@b.cl', 'test', $xmlString);

        $token = new Token('sii-token', new DateTimeImmutable('+1 hour'));
        $this->app->make(Repository::class)->put('dte|soap_token|business:761234567', $token);

        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $mockResponse = new stdClass;
        $mockResponse->getEstDteAvResult = <<<'XML'
            <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
                <SII:RESP_BODY>
                    <CODIGO_ESTADO>1</CODIGO_ESTADO>
                </SII:RESP_BODY>
            </SII:RESPUESTA>
            XML;

        $tenant = clone $mockResponse;

        $this->mock(TenantResolverInterface::class, static function (MockInterface $mock) use ($tenant): void {
            $mock->expects('resolve')->andReturn($tenant);
        });

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockResponse): void {
            $soapClient = Mockery::mock(SoapClient::class);
            $soapClient->expects('__setSoapHeaders');
            $soapClient->expects('__soapCall')->andReturn($mockResponse);

            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('withOptions')->andReturnSelf();
            $mock->expects('build')->andReturn($soapClient);
        });

        Event::fake();

        $processor = $this->app->make(InboundDteProcessor::class);
        $processor->process($emailData);

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'folio' => 1002,
            'status' => InboundDteStatus::Forged,
        ]);

        Event::assertDispatched(InboundForgedDteReceived::class, function ($e) use ($tenant) {
            return $e->tenant === $tenant && $e->document->folio === 1002;
        });
    }

    public function test_throws_when_root_is_unsupported(): void
    {
        $xmlString = '<Unsupported></Unsupported>';
        $emailData = new InboundEmailData('msg-125', 'a@b.cl', 'test', $xmlString);

        $this->mock(TenantResolverInterface::class);
        $processor = $this->app->make(InboundDteProcessor::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unsupported root XML element for inbound processing: Unsupported');

        $processor->process($emailData);
    }

    public function test_throws_when_rutreceptor_is_missing_in_enviodte(): void
    {
        $xmlString = '<EnvioDTE><SetDTE><Caratula></Caratula></SetDTE></EnvioDTE>';
        $emailData = new InboundEmailData('msg-126', 'a@b.cl', 'test', $xmlString);

        $this->mock(TenantResolverInterface::class);
        $processor = $this->app->make(InboundDteProcessor::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Missing RutReceptor in EnvioDTE.');

        $processor->process($emailData);
    }

    public function test_throws_when_tenant_not_found(): void
    {
        $xmlString = '<EnvioDTE><SetDTE><Caratula><RutReceptor>76123456-7</RutReceptor></Caratula></SetDTE></EnvioDTE>';
        $emailData = new InboundEmailData('msg-127', 'a@b.cl', 'test', $xmlString);

        $this->mock(TenantResolverInterface::class, static function (MockInterface $mock): void {
            $mock->expects('resolve')->andReturn(null);
        });

        $processor = $this->app->make(InboundDteProcessor::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Tenant for RUT 76123456-7 not found.');

        $processor->process($emailData);
    }
}
