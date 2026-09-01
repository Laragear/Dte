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
        Pipes\RetrievePendingSiiDte::class,
        Pipes\OutputIecvSales::class,
        Pipes\SendTestingIecv::class,
    ];
}
