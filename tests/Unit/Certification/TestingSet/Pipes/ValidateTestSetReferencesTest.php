<?php

namespace Tests\Unit\Certification\TestingSet\Pipes;

use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laragear\Dte\Certification\TestingSet\Pipes\ValidateTestSetReferences;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDtePayload;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;

class ValidateTestSetReferencesTest extends DatabaseTestCase
{
    /*
     |---------- | Happy Paths | ---------- |
     */

    public function test_validates_and_sorts_by_case_order(): void
    {
        $rut = new Rut(76_123_456, 0);

        $dteA = $this->createDteWithTestSetRef($rut, '5034081-3');
        $dteB = $this->createDteWithTestSetRef($rut, '5034081-1');
        $dteC = $this->createDteWithTestSetRef($rut, '5034081-2');

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dteA, $dteB, $dteC]));

        $pipe = new ValidateTestSetReferences;
        $result = $pipe->handle($data, fn (TestSetData $d) => $d);

        static::assertSame($dteB->id, $result->dtes[0]->id);
        static::assertSame($dteC->id, $result->dtes[1]->id);
        static::assertSame($dteA->id, $result->dtes[2]->id);

        static::assertSame(1, $dteB->fresh()->metadata->sort_order);
        static::assertSame(2, $dteC->fresh()->metadata->sort_order);
        static::assertSame(3, $dteA->fresh()->metadata->sort_order);
    }

    public function test_throws_on_missing_set_reference(): void
    {
        $rut = new Rut(76_123_456, 0);

        $dte = SiiDte::factory()->create(['issuer_rut' => $rut]);
        SiiDtePayload::factory()->create([
            'sii_dte_id' => $dte->id,
            'data' => ['references' => []],
        ]);

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dte]));

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('is missing the required SET/CASO reference');

        (new ValidateTestSetReferences)->handle($data, fn (TestSetData $d) => $d);
    }

    public function test_throws_on_wrong_reason_format(): void
    {
        $rut = new Rut(76_123_456, 0);

        $dte = SiiDte::factory()->create(['issuer_rut' => $rut]);
        SiiDtePayload::factory()->create([
            'sii_dte_id' => $dte->id,
            'data' => ['references' => [['document_type' => 'SET', 'folio' => '0', 'reason' => 'wrong format']]],
        ]);

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dte]));

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('is missing the required SET/CASO reference');

        (new ValidateTestSetReferences)->handle($data, fn (TestSetData $d) => $d);
    }

    public function test_throws_on_duplicate_case(): void
    {
        $rut = new Rut(76_123_456, 0);

        $dte1 = $this->createDteWithTestSetRef($rut, '5034081-1');
        $dte2 = $this->createDteWithTestSetRef($rut, '5034081-1');

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dte1, $dte2]));

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Duplicate test case');

        (new ValidateTestSetReferences)->handle($data, fn (TestSetData $d) => $d);
    }

    public function test_throws_when_set_reference_is_not_first(): void
    {
        $rut = new Rut(76_123_456, 0);

        $dte = SiiDte::factory()->create(['issuer_rut' => $rut]);
        SiiDtePayload::factory()->create([
            'sii_dte_id' => $dte->id,
            'data' => ['references' => [
                ['document_type' => '33', 'folio' => '1', 'reason' => 'Anula documento'],
                ['document_type' => 'SET', 'folio' => '0', 'reason' => 'CASO 5034081-1'],
            ]],
        ]);

        $data = new TestSetData($rut, dtes: EloquentCollection::make([$dte]));

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('is missing the required SET/CASO reference');

        (new ValidateTestSetReferences)->handle($data, fn (TestSetData $d) => $d);
    }

    /*
     |---------- | Helpers | ---------- |
     */

    private function createDteWithTestSetRef(Rut $rut, string $case): SiiDte
    {
        $dte = SiiDte::factory()->create(['issuer_rut' => $rut]);
        SiiDtePayload::factory()->create([
            'sii_dte_id' => $dte->id,
            'data' => ['references' => [['document_type' => 'SET', 'folio' => '0', 'reason' => "CASO {$case}"]]],
        ]);

        return $dte;
    }
}
