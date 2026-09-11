<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;

class TestSetSalesBook extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\RetrieveReportedSiiDte::class,
        Pipes\ResolveIecvCompanyData::class,
        Pipes\OutputIecvSales::class,
        Pipes\SendTestingIecv::class,
    ];
}
