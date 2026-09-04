<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Certification\Pipes\EnsureDtesCompiled;

class TestSetPurchasesBook extends Pipeline
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
        Pipes\OutputIecvPurchases::class,
        Pipes\SendTestingIecv::class,
    ];
}
