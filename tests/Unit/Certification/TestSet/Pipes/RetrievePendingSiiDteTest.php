<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrievePendingSiiDte;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetEnvelope;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class RetrievePendingSiiDteTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public static function providesOneOrManyPendingSiiDte(): iterable
    {
        return [
            'one' => [fn(Rut $rut) => SiiDte::factory(['issuer_rut' => $rut])->create()],
            'many' => [fn(Rut $rut) => SiiDte::factory(2, ['issuer_rut' => $rut])->create()],
        ];
    }

    #[DataProvider('providesOneOrManyPendingSiiDte')]
    public function test_retrieves_at_least_one_pending_sii_dte(Closure $createSiiDte): void
    {
        $rut = new Rut(76_123_456, 0);

        $createSiiDte($rut);

        $this
            ->pipeline(TestSetEnvelope::class)
            ->isolatePipe(RetrievePendingSiiDte::class)
            ->send(new TestSetData($rut))
            ->assertPassable(function (TestSetData $data) {
                static::assertNotEmpty($data->dtes);

                return true;
            });
    }

    /*
     |--------------------------------------------------------------------------
     | Sad Paths
     |--------------------------------------------------------------------------
     */

    public function test_fails_when_no_sii_dte_are_found(): void
    {
        $pipeline = $this->pipeline(TestSetEnvelope::class)
            ->isolatePipe(RetrievePendingSiiDte::class);

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessageIs('No eligible DTEs found for the Test Set. Create the DTEs first, or check their status.');

        $pipeline->send(new TestSetData(new Rut(76_123_456, 0)));
    }

    public function test_fails_when_sii_dte_exist_for_other_rut(): void
    {
        SiiDte::factory(['issuer_rut' => '76.123.456-1'])->create();

        $pipeline = $this->pipeline(TestSetEnvelope::class)
            ->isolatePipe(RetrievePendingSiiDte::class);

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessageIs('No eligible DTEs found for the Test Set. Create the DTEs first, or check their status.');

        $pipeline->send(new TestSetData(new Rut(76_123_456, 0)));
    }

    public function test_retrieves_only_specified_dtes_when_ids_are_provided(): void
    {
        $dte1 = SiiDte::factory()->create(['issuer_num' => 76123456, 'issuer_vd' => '0']);
        $dte2 = SiiDte::factory()->create(['issuer_num' => 76123456, 'issuer_vd' => '0']);
        $dte3 = SiiDte::factory()->create(['issuer_num' => 76123456, 'issuer_vd' => '0']);

        $rut = new Rut(76123456, '0');
        $data = new TestSetData($rut);
        $data->dteIds = [$dte1->id, $dte3->id];

        $pipe = new RetrievePendingSiiDte;
        $result = $pipe->handle($data, function ($data) {
            return $data;
        });

        static::assertCount(2, $result->dtes);
        static::assertTrue($result->dtes->contains('id', $dte1->id));
        static::assertTrue($result->dtes->contains('id', $dte3->id));
        static::assertFalse($result->dtes->contains('id', $dte2->id));
    }

    public function test_skips_query_when_dtes_are_pre_loaded(): void
    {
        $dtes = SiiDte::factory(2)->create(['issuer_num' => 76123456, 'issuer_vd' => '0']);

        $data = new TestSetData(new Rut(76123456, '0'), dtes: $dtes);

        $pipe = new RetrievePendingSiiDte;
        $result = $pipe->handle($data, function ($data) {
            return $data;
        });

        static::assertCount(2, $result->dtes);
        static::assertTrue($result->dtes->contains('id', $dtes[0]->id));
        static::assertTrue($result->dtes->contains('id', $dtes[1]->id));
    }

    public function test_excludes_rejected_and_annulled_dtes(): void
    {
        $rut = new Rut(76_123_456, 0);

        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Pending]);
        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Signed]);
        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Rejected]);
        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Annulled]);
        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Failed]);

        $pipe = new RetrievePendingSiiDte;
        $result = $pipe->handle(new TestSetData($rut), function ($data) {
            return $data;
        });

        static::assertCount(2, $result->dtes);
    }

    public function test_includes_sent_and_accepted_dtes(): void
    {
        $rut = new Rut(76_123_456, 0);

        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Sent]);
        SiiDte::factory()->create(['issuer_rut' => $rut, 'status' => DteStatus::Accepted]);

        $pipe = new RetrievePendingSiiDte;
        $result = $pipe->handle(new TestSetData($rut), function ($data) {
            return $data;
        });

        static::assertCount(2, $result->dtes);
    }
}
