<?php

namespace Tests\Unit\Certification\PrintSample;

use Laragear\Dte\Certification\PrintSample\Pipes\EnsureDteExist;
use Laragear\Dte\Certification\PrintSample\Pipes\GeneratePdfs;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\TestCase;

class PrintSampleTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(PrintSample::class)->assertPipes([
            EnsureDteExist::class,
            GeneratePdfs::class,
        ]);
    }
}
