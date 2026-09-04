<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Certification\Pipes\EnsureDtesCompiled;

class TestSetSalesBook extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        EnsureDtesCompiled::class,
        Pipes\RetrievePendingSiiDte::class,
        Pipes\ResolveIecvCompanyData::class,
        Pipes\OutputIecvSales::class,
        Pipes\SendTestingIecv::class,
    ];
}
