<?php

namespace Tests\Feature\Actions\CompileDte;

use Laragear\Dte\Enums\DteStatus;
use Tests\Feature\Actions\Fixtures\GroundTruthSet;
use Tests\Feature\Actions\GroundTruthTestCase;

class CompileTest extends GroundTruthTestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_compiles_all_set_cases(): void
    {
        $compiled = [];
        $recorded = [];

        foreach (range(1, 8) as $case) {
            $dte = GroundTruthSet::compileCase($case, $compiled);

            static::assertEquals(DteStatus::Signed, $dte->status);
            static::assertSame(GroundTruthSet::expectedFolio($case), $dte->folio);

            $goldenFile = GroundTruthSet::goldenFilename($case);
            $stubPath = static::STUBS . '/' . $goldenFile;

            if (!file_exists($stubPath)) {
                file_put_contents($stubPath, $dte->payload->xml);
                $recorded[] = $case;
                continue;
            }

            $this->assertXmlMatchesGroundTruth($goldenFile, $dte->payload->xml);

            static::assertDatabaseHas('sii_dte_payloads', [
                'sii_dte_id' => $dte->id,
                'xml' => $dte->payload->xml,
            ]);
        }

        if ($recorded !== []) {
            static::markTestIncomplete('Golden files recorded for cases: ' . implode(', ', $recorded));
        }
    }
}
