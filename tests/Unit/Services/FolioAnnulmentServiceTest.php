<?php

namespace Tests\Unit\Services;

use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Services\FolioAnnulmentService;
use RuntimeException;
use Tests\TestCase;

class FolioAnnulmentServiceTest extends TestCase
{
    public function test_annuls_folio(): void
    {
        $dte = SiiDte::factory()->make([
            'issuer_rut' => '76.192.083-9',
            'document_type' => DteType::Invoice,
            'folio' => 123,
        ]);

        $this->mock(SoapGateway::class)
            ->expects('query')
            ->with(
                (string) $dte->issuer_rut,
                'RegistroAnulacionFolios',
                'anularFolios',
                [
                    'RutEmpresa' => 76192083,
                    'DvEmpresa' => '9',
                    'TipoDocumento' => 33,
                    'Folio' => 123,
                    'MotivoAnulacion' => 'Damaged',
                ]
            )
            ->once()
            ->andReturn('success');

        $service = $this->app->make(FolioAnnulmentService::class);

        $result = $service->annul($dte, 'Damaged');

        static::assertSame('success', $result);
    }

    public function test_sad_path_annul_error(): void
    {
        $dte = SiiDte::factory()->make([
            'issuer_rut' => '76.192.083-9',
            'document_type' => DteType::Invoice,
            'folio' => 123,
        ]);

        $this->mock(SoapGateway::class)
            ->expects('query')
            ->once()
            ->andThrow(new RuntimeException('SOAP Error'));

        $service = $this->app->make(FolioAnnulmentService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SOAP Error');

        $service->annul($dte, 'Damaged');
    }
}
