<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use Illuminate\Database\Eloquent\Collection;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Certification\IecvBuilder;
use Laragear\Dte\Certification\IecvType;
use Laragear\Dte\Certification\TestingSet\Pipes\OutputIecvSales;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetSalesBook;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use RuntimeException;
use Tests\TestCase;
use function now;

class OutputIecvSalesTest extends TestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public function test_builds_and_stores_iecv_xml(): void
    {
        $passable = new TestSetData(
            Rut::parse('76.123.456-0'),
            [],
            SiiDte::factory(2, ['issuer_rut' => new Rut(76_123_456, 0)])->makeMany(),
            '2026-01',
            '2026-01-01',
            1,
            new Rut(22_222_222, 2),
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
            ->expects('build')
            ->withArgs(static function (
                Collection $dtes,
                IecvType $type,
                string $period,
                string $resolutionDate,
                int $resolutionNumber,
                Rut $senderRut,
            ) use ($passable): true {
                static::assertSame($passable->dtes, $dtes);
                static::assertSame(IecvType::Sales, $type);
                static::assertSame($passable->period, $period);
                static::assertSame($passable->resolutionDate, $resolutionDate);
                static::assertSame($passable->resolutionNumber, $resolutionNumber);
                static::assertSame($passable->senderRut, $senderRut);

                return true;
            })
            ->andReturn($xmlString);

        $this
            ->mock(XmlSigner::class)
            ->expects('sign')
            ->withArgs(function ($node, $cert) use ($digitalCertificate) {
                return $cert === $digitalCertificate;
            });

        $this
            ->pipeline(TestSetSalesBook::class)
            ->isolatePipe(OutputIecvSales::class)
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

    public function test_fails_when_no_certificate_is_found(): void
    {
        $this->mock(CertificateResolver::class)->expects('resolve')->andReturnNull();
        $this->mock(IecvBuilder::class)->expects('build')->never();
        $this->mock(XmlSigner::class)->expects('sign')->never();

        $pipeline = $this->pipeline(TestSetSalesBook::class)
            ->isolatePipe(OutputIecvSales::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No certificate was found for [76.123.456-0].');

        $pipeline->send(new TestSetData(
            new Rut(76_123_456, 0),
            [],
            new Collection,
            '2026-01',
            '2026-01-01',
            1,
            new Rut(22_222_222, 2),
        ));
    }

    public function test_throws_unable_to_parse_iecv_xml(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to parse the IECV XML.');

        $data = new TestSetData(
            new Rut('76000000', '0'),
            [],
            Collection::empty(),
            '2026-01',
            '2026-01-01',
            0,
            new Rut('76000000', '0'),
        );

        $this->mock(IecvBuilder::class)->expects('build')->andReturn('invalid xml');

        $this->mock(CertificateResolver::class)->expects('resolve')->andReturn(new DigitalCertificate('fake', 'fake'));

        $pipe = $this->app->make(OutputIecvSales::class);
        $pipe->handle($data, fn($d) => $d);
    }

    public function test_throws_unable_to_find_envio_libro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to find EnvioLibro in IECV XML.');

        $data = new TestSetData(
            new Rut('76000000', '0'),
            [],
            Collection::empty(),
            '2026-01',
            '2026-01-01',
            0,
            new Rut('76000000', '0'),
        );

        $this->mock(IecvBuilder::class)->expects('build')->andReturn('<?xml version="1.0"?><WrongRoot></WrongRoot>');

        $this->mock(CertificateResolver::class)->expects('resolve')->andReturn(new DigitalCertificate('fake', 'fake'));

        $pipe = $this->app->make(OutputIecvSales::class);
        $pipe->handle($data, fn($d) => $d);
    }
}
