<?php

namespace Tests\Unit\Certification\PrintSample\Pipes;

use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\PrintSample\Pipes\EnsureDteExist;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Models\SiiDte;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\DatabaseTestCase;
use function now;

class EnsureDteExistTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    public function test_retrieves_sii_dte_by_ids(): void
    {
        $this->expectNotToPerformAssertions();

        $dte1 = SiiDte::factory()->create([
            'issuer_rut' => '76123456-0',
            'document_type' => 33,
            'created_at' => now(),
        ]);
        $dte2 = SiiDte::factory()->create([
            'issuer_rut' => '76123456-0',
            'document_type' => 33,
            'created_at' => now(),
        ]);
        $dte3 = SiiDte::factory()->create([
            'issuer_rut' => '76123456-0',
            'document_type' => 34,
            'created_at' => now(),
        ]);

        $data = new PrintSampleData(new Rut(76_123_456, 0), [$dte1->id, $dte2->id, $dte3->id]);

        $this->pipeline(PrintSample::class)
            ->isolatePipe(EnsureDteExist::class)
            ->send($data);
    }

    public function test_retrieves_all_dtes_when_no_ids_provided(): void
    {
        $this->expectNotToPerformAssertions();

        SiiDte::factory()->create([
            'issuer_rut' => '76123456-0',
            'document_type' => 33,
            'created_at' => now(),
        ]);

        $data = new PrintSampleData(new Rut(76_123_456, 0));

        $this->pipeline(PrintSample::class)
            ->isolatePipe(EnsureDteExist::class)
            ->send($data);
    }

    public function test_fails_when_no_dtes_found(): void
    {
        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessageIs('No DTEs found. You need to create the DTEs first (Step 1).');

        $data = new PrintSampleData(new Rut(76_123_456, 0));

        $this->pipeline(PrintSample::class)
            ->isolatePipe(EnsureDteExist::class)
            ->send($data);
    }
}
