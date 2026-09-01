<?php

namespace Tests\Unit\Certification\TestSet;

use Laragear\Dte\Certification\Simulation\Pipes\CompileEnvelope;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrievePendingSiiDte;
use Laragear\Dte\Certification\TestingSet\Pipes\SendTestingEnvelope;
use Laragear\Dte\Certification\TestingSet\TestSetEnvelope;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\TestCase;

class TestSetEnvelopeTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(TestSetEnvelope::class)->assertPipes([
            RetrievePendingSiiDte::class,
            CompileEnvelope::class,
            SendTestingEnvelope::class,
        ]);
    }
}
