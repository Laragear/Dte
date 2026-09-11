<?php

namespace Tests\Unit\Certification\TestSet;

use Laragear\Dte\Certification\TestingSet\Pipes\OutputIecvSales;
use Laragear\Dte\Certification\TestingSet\Pipes\ResolveIecvCompanyData;
use Laragear\Dte\Certification\TestingSet\Pipes\RetrieveReportedSiiDte;
use Laragear\Dte\Certification\TestingSet\Pipes\SendTestingIecv;
use Laragear\Dte\Certification\TestingSet\TestSetSalesBook;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\TestCase;

class TestSetSalesBookTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_check_pipes_order(): void
    {
        $this->pipeline(TestSetSalesBook::class)->assertPipes([
            RetrieveReportedSiiDte::class,
            ResolveIecvCompanyData::class,
            OutputIecvSales::class,
            SendTestingIecv::class,
        ]);
    }
}
