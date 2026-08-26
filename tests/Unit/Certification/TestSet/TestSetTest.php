<?php

namespace Tests\Unit\Certification\TestSet;

use Laragear\Dte\Certification\TestingSet\Pipes\OutputIecv;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrievePendingSiiDte;
use Laragear\Dte\Certification\TestingSet\TestSet;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class TestSetTest extends TestCase
{
    use InteractsWithPipelines;

    /*
    |--------------------------------------------------------------------------
    | Happy paths
    |--------------------------------------------------------------------------
    */

    public function test_check_pipes_order(): void
    {
        $this->pipeline(TestSet::class)->assertPipes([
            RetrievePendingSiiDte::class,
            OutputIecv::class,
        ]);
    }

    public function test_receives_rut_into_passable(): void
    {
        $pipeline = $this->app->make(TestSet::class);

        $pipeline->through([]);

        $result = $pipeline->forRut(new Rut(76123456, 0));

        static::assertEquals('76.123.456-0', $result->rut);
    }
}
