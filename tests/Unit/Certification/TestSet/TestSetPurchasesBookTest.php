<?php

namespace Tests\Unit\Certification\TestSet;

use Laragear\Dte\Certification\TestingSet\Pipes\OutputIecvPurchases;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrievePendingSiiDte;
use Laragear\Dte\Certification\TestingSet\Pipes\SendTestingIecv;
use Laragear\Dte\Certification\TestingSet\TestSetPurchasesBook;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\TestCase;

class TestSetPurchasesBookTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(TestSetPurchasesBook::class)->assertPipes([
            RetrievePendingSiiDte::class,
            OutputIecvPurchases::class,
            SendTestingIecv::class,
        ]);
    }
}
