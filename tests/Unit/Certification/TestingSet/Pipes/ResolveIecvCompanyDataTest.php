<?php

namespace Tests\Unit\Certification\TestingSet\Pipes;

use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laragear\Dte\Certification\IecvPurchaseData;
use Laragear\Dte\Certification\TestingSet\Pipes\ResolveIecvCompanyData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDte;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class ResolveIecvCompanyDataTest extends DatabaseTestCase
{
    /*
     |---------- | Happy paths | ---------- |
     */

    public function test_resolves_company_data_when_fields_are_empty(): void
    {
        $manager = \Mockery::mock(ConfigurationManager::class);
        $manager->expects('getIssuer')
            ->once()
            ->andReturn(new IssuerData(
                rut: Rut::parse('11111111-1'),
                legalName: 'Test Company',
                businessActivity: 'Tech',
                economicActivity: ['620100'],
                address: 'Address 1',
                commune: 'Santiago',
                resolutionDate: '2023-06-01',
                resolutionNumber: 999,
            ));
        $manager->expects('hasSenderResolver')->once()->andReturn(false);

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
        );

        $called = false;
        $result = $pipe->handle($data, function (TestSetData $data) use (&$called) {
            $called = true;

            return $data;
        });

        static::assertTrue($called);
        static::assertSame('2023-06-01', $result->resolutionDate);
        static::assertSame(999, $result->resolutionNumber);
    }

    /*
     |---------- | Lines 37 & 41 — Preserve existing values | ---------- |
     */

    public function test_preserves_existing_resolution_date_when_already_set(): void
    {
        // Line 37: resolutionDate is already provided → keep it
        $manager = \Mockery::mock(ConfigurationManager::class);
        $manager->expects('getIssuer')
            ->once()
            ->andReturn(new IssuerData(
                rut: Rut::parse('11111111-1'),
                legalName: 'Test',
                businessActivity: 'Tech',
                economicActivity: ['620100'],
                address: 'Addr',
                commune: 'Santiago',
                resolutionDate: '2023-06-01',
                resolutionNumber: 999,
            ));
        $manager->expects('hasSenderResolver')->once()->andReturn(false);

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2024-01-15', // Already set
        );

        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('2024-01-15', $result->resolutionDate);
        static::assertSame(999, $result->resolutionNumber);
    }

    public function test_preserves_existing_resolution_number_when_already_set(): void
    {
        // Line 41: resolutionNumber is already provided → keep it
        $manager = \Mockery::mock(ConfigurationManager::class);
        $manager->expects('getIssuer')
            ->once()
            ->andReturn(new IssuerData(
                rut: Rut::parse('11111111-1'),
                legalName: 'Test',
                businessActivity: 'Tech',
                economicActivity: ['620100'],
                address: 'Addr',
                commune: 'Santiago',
                resolutionDate: '2023-06-01',
                resolutionNumber: 999,
            ));
        $manager->expects('hasSenderResolver')->once()->andReturn(false);

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionNumber: 555, // Already set
        );

        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('2023-06-01', $result->resolutionDate);
        static::assertSame(555, $result->resolutionNumber);
    }

    public function test_skips_issuer_resolution_when_both_fields_are_set(): void
    {
        $manager = \Mockery::mock(ConfigurationManager::class);
        // getIssuer should NOT be called since both fields are set and the condition is
        // `if ($data->resolutionDate === '' || $data->resolutionNumber === 0)`
        // Both are non-empty/non-zero, so the condition is false → skip.
        $manager->expects('getIssuer')->never();
        $manager->expects('hasSenderResolver')->once()->andReturn(false);

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2024-01-15',
            resolutionNumber: 555,
        );

        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('2024-01-15', $result->resolutionDate);
        static::assertSame(555, $result->resolutionNumber);
    }

    /*
     |---------- | Sender resolution | ---------- |
     */

    public function test_resolves_sender_rut_when_not_set_and_resolver_exists(): void
    {
        $manager = \Mockery::mock(ConfigurationManager::class);
        $manager->expects('getIssuer')
            ->once()
            ->andReturn(new IssuerData(
                rut: Rut::parse('11111111-1'),
                legalName: 'Test',
                businessActivity: 'Tech',
                economicActivity: ['620100'],
                address: 'Addr',
                commune: 'Santiago',
                resolutionDate: '2023-06-01',
                resolutionNumber: 999,
            ));
        $manager->expects('hasSenderResolver')->once()->andReturn(true);
        $manager->expects('getSender')
            ->once()
            ->andReturnUsing(fn (Rut $rut) => Rut::parse('22222222-2'));

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
        );

        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('222222222', $result->senderRut->formatRaw());
    }

    public function test_sets_sender_rut_to_issuer_when_no_resolver(): void
    {
        $manager = \Mockery::mock(ConfigurationManager::class);
        $manager->expects('getIssuer')
            ->once()
            ->andReturn(new IssuerData(
                rut: Rut::parse('11111111-1'),
                legalName: 'Test',
                businessActivity: 'Tech',
                economicActivity: ['620100'],
                address: 'Addr',
                commune: 'Santiago',
                resolutionDate: '2023-06-01',
                resolutionNumber: 999,
            ));
        $manager->expects('hasSenderResolver')->once()->andReturn(false);

        $pipe = new ResolveIecvCompanyData($manager);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
        );

        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('111111111', $result->senderRut->formatRaw());
    }

    /*
     |---------- | Period from entries | ---------- |
     */

    public function test_resolves_period_from_purchase_entries(): void
    {
        $entries = [
            IecvPurchaseData::make(DteType::Invoice, 1, '2025-06-15', '77777777-7'),
        ];

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2023-06-01',
            resolutionNumber: 999,
            senderRut: Rut::parse('11111111-1'),
            purchaseEntries: $entries,
        );

        $result = $this->pipe()->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('2025-06', $result->period);
    }

    public function test_resolves_empty_period_with_non_iecv_entries(): void
    {
        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2023-06-01',
            resolutionNumber: 999,
            senderRut: Rut::parse('11111111-1'),
            purchaseEntries: [['foo' => 'bar']],
        );

        $result = $this->pipe()->handle($data, fn (TestSetData $d) => $d);

        static::assertSame('', $result->period);
    }

    public function test_validates_non_iecv_entry_period_throws(): void
    {
        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'issued_on' => '2025-06-01',
        ]);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2023-06-01',
            resolutionNumber: 999,
            senderRut: Rut::parse('11111111-1'),
            dtes: EloquentCollection::make([$dte]),
            purchaseEntries: [['foo' => 'bar']],
        );

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('All purchase entries must share the same tax period');

        $this->pipe()->handle($data, fn (TestSetData $d) => $d);
    }

    public function test_throws_on_mixed_dte_periods(): void
    {
        $dte1 = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'issued_on' => '2025-06-01',
        ]);
        $dte2 = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'issued_on' => '2025-07-01',
        ]);

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2023-06-01',
            resolutionNumber: 999,
            senderRut: Rut::parse('11111111-1'),
            dtes: EloquentCollection::make([$dte1, $dte2]),
        );

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('All Test Set documents must share the same tax period');

        $this->pipe()->handle($data, fn ($d) => $d);
    }

    public function test_throws_on_mixed_entry_periods(): void
    {
        $entries = [
            IecvPurchaseData::make(DteType::Invoice, 1, '2025-06-15', '77777777-7'),
            IecvPurchaseData::make(DteType::Invoice, 2, '2025-07-15', '77777777-7'),
        ];

        $data = new TestSetData(
            rut: Rut::parse('11111111-1'),
            resolutionDate: '2023-06-01',
            resolutionNumber: 999,
            senderRut: Rut::parse('11111111-1'),
            purchaseEntries: $entries,
        );

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('All purchase entries must share the same tax period');

        $this->pipe()->handle($data, fn ($d) => $d);
    }

    private function pipe(): ResolveIecvCompanyData
    {
        return $this->app->make(ResolveIecvCompanyData::class);
    }
}
