<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;

class TestSetPurchasesBook extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\RetrievePendingSiiDte::class,
        Pipes\OutputIecvPurchases::class,
        Pipes\SendTestingIecv::class,
    ];
}
