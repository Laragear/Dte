<?php

namespace Tests\Unit\Certification\PrintSample\Pipes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laragear\Dte\Certification\PrintSample\Pipes\GeneratePdfs;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Data\PdfData;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Pdf\PdfBuilder;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;

class GeneratePdfsTest extends DatabaseTestCase
{
    use InteractsWithPipelines;
    use RefreshDatabase;

    public function test_generates_pdfs_for_given_dte_ids(): void
    {
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

        $this->mock(PdfBuilder::class, function (MockInterface $mock) {
            $mock->expects('forDte')->times(3)->andReturnSelf();
            $mock->expects('generate')->times(3)->andReturn(
                new PdfData('local', 'pdf1.pdf'),
                new PdfData('local', 'pdf2.pdf'),
                new PdfData('local', 'pdf3.pdf')
            );
        });

        $data = new PrintSampleData(new Rut(76_123_456, 0), [$dte1->id, $dte2->id, $dte3->id]);

        $this->pipeline(PrintSample::class)
            ->isolatePipe(GeneratePdfs::class)
            ->send($data)
            ->assertPassable(function (PrintSampleData $result) {
                static::assertCount(3, $result->pdfs);
                static::assertEquals('pdf1.pdf', $result->pdfs[0]->path);
                static::assertEquals('pdf2.pdf', $result->pdfs[1]->path);
                static::assertEquals('pdf3.pdf', $result->pdfs[2]->path);

                return true;
            });
    }
}
