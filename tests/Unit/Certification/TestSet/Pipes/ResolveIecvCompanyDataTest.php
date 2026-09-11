<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use Laragear\Dte\Certification\TestingSet\Pipes\ResolveIecvCompanyData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetSalesBook;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Models\SiiDte;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class ResolveIecvCompanyDataTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_resolves_company_data_from_configuration(): void
    {
        $issuer = new IssuerData(
            new Rut(76_123_456, 0),
            'Test Company',
            'Activity',
            'Economic',
            'Address',
            'Santiago',
            '2020-01-01',
            654321,
        );

        $this->mock(ConfigurationManager::class, function ($mock) use ($issuer): void {
            $mock->expects('getIssuer')
                ->withArgs(function (Rut $rut): bool {
                    return $rut->formatBasic() === '76123456-0';
                })
                ->andReturn($issuer);

            $mock->expects('hasSenderResolver')->andReturn(true);

            $mock->expects('getSender')
                ->withArgs(function (Rut $rut): bool {
                    return $rut->formatBasic() === '76123456-0';
                })
                ->andReturn(new Rut(22_222_222, 2));
        });

        $dtes = SiiDte::factory(1, [
            'issuer_rut' => new Rut(76_123_456, 0),
            'issued_on' => '2023-10-05',
        ])->makeMany();

        $data = new TestSetData(Rut::parse('76.123.456-0'), [], $dtes);

        $this->pipeline(TestSetSalesBook::class)
            ->isolatePipe(ResolveIecvCompanyData::class)
            ->send($data)
            ->assertPassable(function (TestSetData $result): bool {
                static::assertSame('2020-01-01', $result->resolutionDate);
                static::assertSame(654321, $result->resolutionNumber);
                static::assertSame('22222222-2', $result->senderRut->formatBasic());
                static::assertSame('2023-10', $result->period);

                return true;
            });
    }

    public function test_does_not_override_already_set_values(): void
    {
        $issuer = new IssuerData(
            new Rut(76_123_456, 0),
            'Test Company',
            'Activity',
            'Economic',
            'Address',
            'Santiago',
            '2020-01-01',
            654321,
        );

        $mock = $this->mock(ConfigurationManager::class);
        $mock->expects('getIssuer')->never();
        $mock->expects('hasSenderResolver')->never();
        $mock->expects('getSender')->never();

        $dtes = SiiDte::factory(1, [
            'issuer_rut' => new Rut(76_123_456, 0),
            'issued_on' => '2023-10-05',
        ])->makeMany();

        $senderRut = new Rut(22_222_222, 2);

        $data = new TestSetData(
            rut: Rut::parse('76.123.456-0'),
            dtes: $dtes,
            period: '2023-10',
            resolutionDate: '2020-01-01',
            resolutionNumber: 654321,
            senderRut: $senderRut,
        );

        $this->pipeline(TestSetSalesBook::class)
            ->isolatePipe(ResolveIecvCompanyData::class)
            ->send($data)
            ->assertPassable(function (TestSetData $result) use ($senderRut): bool {
                static::assertSame('2020-01-01', $result->resolutionDate);
                static::assertSame(654321, $result->resolutionNumber);
                static::assertSame($senderRut, $result->senderRut);
                static::assertSame('2023-10', $result->period);

                return true;
            });
    }

    public function test_defaults_sender_rut_to_company_rut_when_no_sender_resolver(): void
    {
        $issuer = new IssuerData(
            new Rut(76_123_456, 0),
            'Test Company',
            'Activity',
            'Economic',
            'Address',
            'Santiago',
            '2020-01-01',
            654321,
        );

        $this->mock(ConfigurationManager::class, function ($mock) use ($issuer): void {
            $mock->expects('getIssuer')->andReturn($issuer);
            $mock->expects('hasSenderResolver')->andReturn(false);
            $mock->expects('getSender')->never();
        });

        $dtes = SiiDte::factory(1, [
            'issuer_rut' => new Rut(76_123_456, 0),
            'issued_on' => '2023-10-05',
        ])->makeMany();

        $data = new TestSetData(Rut::parse('76.123.456-0'), [], $dtes);

        $this->pipeline(TestSetSalesBook::class)
            ->isolatePipe(ResolveIecvCompanyData::class)
            ->send($data)
            ->assertPassable(function (TestSetData $result): bool {
                static::assertSame('76123456-0', $result->senderRut->formatBasic());

                return true;
            });
    }
}
