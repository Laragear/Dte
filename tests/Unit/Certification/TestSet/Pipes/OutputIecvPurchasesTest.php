<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Certification\IecvBuilder;
use Laragear\Dte\Certification\IecvPurchaseData;
use Laragear\Dte\Certification\TestingSet\Pipes\OutputIecvPurchases;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetPurchasesBook;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Dte\Xml\XsdValidator;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use RuntimeException;
use Tests\TestCase;
use function now;

class OutputIecvPurchasesTest extends TestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public function test_builds_and_stores_iecv_xml(): void
    {
        $entries = [
            IecvPurchaseData::make(
                documentType: DteType::Invoice,
                folio: 42,
                issuedOn: '2026-01-15',
                issuerRut: Rut::parse('77.777.777-7'),
                amountNet: 1000,
            ),
        ];

        $passable = new TestSetData(
            Rut::parse('76.123.456-0'),
            purchaseEntries: $entries,
            period: '2026-01',
            resolutionDate: '2026-01-01',
            resolutionNumber: 1,
            senderRut: new Rut(22_222_222, 2),
        );

        $xmlString = '<?xml version="1.0" encoding="ISO-8859-1"?>
<LibroCompraVenta><EnvioLibro ID="id"></EnvioLibro></LibroCompraVenta>';

        $digitalCertificate = new DigitalCertificate(
            $passable->rut,
            'foo',
            'bar',
            [],
            now()->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
        );

        $this
            ->mock(CertificateResolver::class)
            ->expects('resolve')
            ->with($passable->rut)
            ->andReturn($digitalCertificate);

        $this
            ->mock(IecvBuilder::class)
            ->expects('buildPurchases')
            ->andReturn($xmlString);

        $this
            ->mock(XsdValidator::class)
            ->expects('validate')
            ->once();

        $this
            ->mock(XmlSigner::class)
            ->expects('sign')
            ->withArgs(function ($node, $cert) use ($digitalCertificate) {
                return $cert === $digitalCertificate;
            });

        $this
            ->pipeline(TestSetPurchasesBook::class)
            ->isolatePipe(OutputIecvPurchases::class)
            ->send($passable)
            ->assertPassable(function (TestSetData $data) use ($xmlString) {
                static::assertSame(str_replace('></EnvioLibro>', '/>', $xmlString)."\n", $data->iecvXml);

                return true;
            });
    }

    /*
     |--------------------------------------------------------------------------
     | Sad Paths
     |--------------------------------------------------------------------------
     */

    public function test_fails_when_no_purchase_entries(): void
    {
        $data = new TestSetData(
            Rut::parse('76.123.456-0'),
        );

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('No purchase entries provided');

        $pipe = $this->app->make(OutputIecvPurchases::class);
        $pipe->handle($data, fn($d) => $d);
    }

    public function test_fails_when_no_certificate_is_found(): void
    {
        $entries = [
            IecvPurchaseData::make(DteType::Invoice, 1, '2026-01-15', '77777777-7'),
        ];

        $this->mock(CertificateResolver::class)->expects('resolve')->andReturnNull();
        $this->mock(IecvBuilder::class)->expects('buildPurchases')->never();
        $this->mock(XmlSigner::class)->expects('sign')->never();

        $pipeline = $this->pipeline(TestSetPurchasesBook::class)
            ->isolatePipe(OutputIecvPurchases::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No certificate was found for [76.123.456-0].');

        $pipeline->send(new TestSetData(
            new Rut(76_123_456, 0),
            purchaseEntries: $entries,
            period: '2026-01',
            resolutionDate: '2026-01-01',
            resolutionNumber: 1,
            senderRut: new Rut(22_222_222, 2),
        ));
    }

    public function test_throws_unable_to_parse_iecv_xml(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to parse the IECV XML.');

        $data = new TestSetData(
            new Rut('76000000', '0'),
            purchaseEntries: [
                IecvPurchaseData::make(DteType::Invoice, 1, '2026-01-15', '77777777-7'),
            ],
            period: '2026-01',
            resolutionDate: '2026-01-01',
            resolutionNumber: 0,
            senderRut: new Rut('76000000', '0'),
        );

        $this->mock(IecvBuilder::class)->expects('buildPurchases')->andReturn('invalid xml');
        $this->mock(XsdValidator::class)->expects('validate')->once();
        $this->mock(CertificateResolver::class)->expects('resolve')->andReturn(new DigitalCertificate('fake', 'fake'));

        $pipe = $this->app->make(OutputIecvPurchases::class);
        $pipe->handle($data, fn($d) => $d);
    }

    public function test_throws_unable_to_find_envio_libro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to find EnvioLibro in IECV XML.');

        $data = new TestSetData(
            new Rut('76000000', '0'),
            purchaseEntries: [
                IecvPurchaseData::make(DteType::Invoice, 1, '2026-01-15', '77777777-7'),
            ],
            period: '2026-01',
            resolutionDate: '2026-01-01',
            resolutionNumber: 0,
            senderRut: new Rut('76000000', '0'),
        );

        $this->mock(IecvBuilder::class)->expects('buildPurchases')->andReturn('<?xml version="1.0"?><WrongRoot></WrongRoot>');
        $this->mock(XsdValidator::class)->expects('validate')->once();
        $this->mock(CertificateResolver::class)->expects('resolve')->andReturn(new DigitalCertificate('fake', 'fake'));

        $pipe = $this->app->make(OutputIecvPurchases::class);
        $pipe->handle($data, fn($d) => $d);
    }
}
