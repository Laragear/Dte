<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Pipeline\Pipeline;
use Laragear\Rut\Rut;

class TestSet extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\RetrievePendingSiiDte::class,
        Pipes\OutputIecv::class,
    ];

    /**
     * Creates a set of test documents for certification using the given RUT.
     */
    public function forRut(Rut $rut): TestSetData
    {
        return $this->send(new TestSetData($rut))->thenReturn();
    }
}
