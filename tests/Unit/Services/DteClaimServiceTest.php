<?php

namespace Tests\Unit\Services;

use DateTimeImmutable;
use Laragear\Dte\Builders\CommercialReceiptBuilder;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Gateways\ReclamoWebserviceGateway;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Services\DteClaimService;
use Laragear\Rut\Rut;
use LogicException;
use RuntimeException;
use Tests\DatabaseTestCase;

class DteClaimServiceTest extends DatabaseTestCase
{
    public function test_accepts_invoice(): void
    {
        $document = SiiInboundDocument::factory()->create();
        $signer = Rut::parse('1-9');
        $location = 'Test location';
        $certificate = new DigitalCertificate('fake', 'fake');
        $signedAt = new DateTimeImmutable;

        $this->mock(ReclamoWebserviceGateway::class)->expects('accept')->with($document)->once();

        $this
            ->mock(CommercialReceiptBuilder::class)
            ->expects('build')
            ->with($document, $signer, $location, $certificate, $signedAt)
            ->once()
            ->andReturn('<xml>erm</xml>');

        $service = $this->app->make(DteClaimService::class);

        $result = $service->accept($document, $signer, $location, $certificate, $signedAt);

        static::assertSame('<xml>erm</xml>', $result);
    }

    public function test_rejects_invoice(): void
    {
        $document = SiiInboundDocument::factory()->create();

        $this->mock(ReclamoWebserviceGateway::class)->expects('reject')->with($document, 'test reason');

        $service = $this->app->make(DteClaimService::class);

        $service->reject($document, 'test reason');
    }

    public function test_rejects_invoice_due_to_missing_goods(): void
    {
        $document = SiiInboundDocument::factory()->create();

        $this->mock(ReclamoWebserviceGateway::class)->expects('rejectGoods')->with($document, 'test reason');

        $service = $this->app->make(DteClaimService::class);

        $service->rejectGoods($document, 'test reason');
    }

    public function test_throws_when_document_already_claimed(): void
    {
        $document = SiiInboundDocument::factory()->create([
            'status' => InboundDteStatus::CommercialAccepted,
        ]);

        $service = $this->app->make(DteClaimService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The document has already been commercially claimed or accepted.');

        $service->reject($document, 'reason');
    }

    public function test_sad_path_accept_timeout_or_error(): void
    {
        $document = SiiInboundDocument::factory()->create();
        $signer = Rut::parse('1-9');
        $location = 'Test location';
        $certificate = new DigitalCertificate('fake', 'fake');
        $signedAt = new DateTimeImmutable;

        $this
            ->mock(ReclamoWebserviceGateway::class)
            ->expects('accept')
            ->with($document)
            ->once()
            ->andThrow(new RuntimeException('SII timeout'));

        $service = $this->app->make(DteClaimService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SII timeout');

        $service->accept($document, $signer, $location, $certificate, $signedAt);
    }
}
