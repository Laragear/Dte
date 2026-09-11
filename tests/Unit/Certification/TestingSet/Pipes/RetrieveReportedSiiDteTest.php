<?php

namespace Tests\Unit\Certification\TestingSet\Pipes;

use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrieveReportedSiiDte;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class RetrieveReportedSiiDteTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public static function providesReportedSiiDte(): iterable
    {
        return [
            'sent' => [DteStatus::Sent],
            'accepted' => [DteStatus::Accepted],
        ];
    }

    #[DataProvider('providesReportedSiiDte')]
    public function test_retrieves_envelope_associated_dtes(DteStatus $status): void
    {
        $rut = new Rut(76_123_456, 0);
        $envelope = SiiDteEnvelope::factory()->create(['issuer_rut' => $rut]);

        SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => $status,
            'sii_dte_envelope_id' => $envelope->id,
        ]);

        $pipe = new RetrieveReportedSiiDte;
        $result = $pipe->handle(new TestSetData($rut), function ($data) {
            return $data;
        });

        static::assertCount(1, $result->dtes);
    }

    public function test_skips_pending_and_signed_dtes(): void
    {
        $rut = new Rut(76_123_456, 0);
        $envelope = SiiDteEnvelope::factory()->create(['issuer_rut' => $rut]);

        SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Pending,
            'sii_dte_envelope_id' => $envelope->id,
        ]);
        SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Signed,
            'sii_dte_envelope_id' => $envelope->id,
        ]);

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('No reported DTEs found');

        $pipe = new RetrieveReportedSiiDte;
        $pipe->handle(new TestSetData($rut), fn ($d) => $d);
    }

    public function test_skips_dtes_without_envelope(): void
    {
        $rut = new Rut(76_123_456, 0);

        SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Sent,
            'sii_dte_envelope_id' => null,
        ]);

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('No reported DTEs found');

        $pipe = new RetrieveReportedSiiDte;
        $pipe->handle(new TestSetData($rut), fn ($d) => $d);
    }

    public function test_fails_when_no_dtes_found(): void
    {
        $pipe = new RetrieveReportedSiiDte;

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('No reported DTEs found');

        $pipe->handle(new TestSetData(new Rut(76_123_456, 0)), fn ($d) => $d);
    }

    public function test_skips_query_when_dtes_are_pre_loaded(): void
    {
        $rut = new Rut(76_123_456, 0);
        $envelope = SiiDteEnvelope::factory()->create(['issuer_rut' => $rut]);

        $dte = SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Sent,
            'sii_dte_envelope_id' => $envelope->id,
        ]);

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dte]));

        $pipe = new RetrieveReportedSiiDte;
        $result = $pipe->handle($data, function ($data) {
            return $data;
        });

        static::assertCount(1, $result->dtes);
        static::assertTrue($result->dtes->contains('id', $dte->id));
    }

    public function test_filters_by_dte_ids(): void
    {
        $rut = new Rut(76_123_456, 0);
        $envelope = SiiDteEnvelope::factory()->create(['issuer_rut' => $rut]);

        $dte1 = SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Sent,
            'sii_dte_envelope_id' => $envelope->id,
        ]);
        $dte2 = SiiDte::factory()->create([
            'issuer_rut' => $rut,
            'status' => DteStatus::Sent,
            'sii_dte_envelope_id' => $envelope->id,
        ]);

        $data = new TestSetData($rut, dteIds: [$dte1->id]);

        $pipe = new RetrieveReportedSiiDte;
        $result = $pipe->handle($data, function ($data) {
            return $data;
        });

        static::assertCount(1, $result->dtes);
        static::assertTrue($result->dtes->contains('id', $dte1->id));
        static::assertFalse($result->dtes->contains('id', $dte2->id));
    }
}
