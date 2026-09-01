<?php

namespace Tests\Unit\Certification\Interchange\Pipes;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Certification\Interchange\Pipes\AcceptAndSendReceipt;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Services\DteClaimService;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AcceptAndSendReceiptTest extends TestCase
{
    use InteractsWithPipelines;
    use RefreshDatabase;

    public function test_accepts_and_sends_receipt(): void
    {
        $inboundDocument = SiiInboundDocument::factory()->make();

        $data = new InterchangeData(new Rut(76_123_456, 0), signerRut: new Rut(76_123_456, 0), location: 'Santiago');
        $data->inboundDocument = $inboundDocument;

        $certificate = new DigitalCertificate(
            new Rut(76_123_456, 0),
            'cert',
            'key',
            [],
            new DateTimeImmutable,
            new DateTimeImmutable('+1 year')
        );

        $this->mock(CertificateResolver::class, function (MockInterface $mock) use ($certificate) {
            $mock->expects('resolve')->once()->withArgs(function (Rut $rut) {
                return $rut->formatBasic() === '76123456-0';
            })->andReturn($certificate);
        });

        $this->mock(DteClaimService::class, function (MockInterface $mock) use ($inboundDocument, $certificate) {
            $mock->expects('accept')->once()->withArgs(function ($document, $signer, $location, $cert) use (
                $inboundDocument,
                $certificate
            ) {
                return $document->is($inboundDocument) &&
                    $signer->formatBasic() === '76123456-0' &&
                    $location === 'Santiago' &&
                    $cert === $certificate;
            });
        });

        $this->pipeline(Interchange::class)
            ->isolatePipe(AcceptAndSendReceipt::class)
            ->send($data)
            ->assertPassable(fn(InterchangeData $result) => $data === $result);
    }

    public function test_fails_when_certificate_not_resolved(): void
    {
        $data = new InterchangeData(
            rut: new Rut(76_123_456, 0),
            signerRut: new Rut(76_123_456, 0),
            location: 'Santiago',
            inboundDocument: SiiInboundDocument::factory()->make(),
        );

        $this->mock(CertificateResolver::class)->expects('resolve')->once()->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Could not resolve digital certificate for 76123456-0.');

        $this->pipeline(Interchange::class)
            ->isolatePipe(AcceptAndSendReceipt::class)
            ->send($data);
    }
}
