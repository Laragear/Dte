<?php

namespace Tests\Unit\Certification\PrintSample;

use Laragear\Dte\Certification\PrintSample\Pipes\GeneratePdfs;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class PrintSampleTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(PrintSample::class)->assertPipes([
            GeneratePdfs::class,
        ]);
    }

    public function test_receives_rut_into_passable(): void
    {
        $pipeline = $this->app->make(PrintSample::class);

        $pipeline->through([]);

        $result = $pipeline->forRut(new Rut(76123456, 0));

        static::assertEquals('76.123.456-0', $result->rut);
    }
}
