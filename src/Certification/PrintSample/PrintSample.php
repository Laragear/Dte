<?php

namespace Laragear\Dte\Certification\PrintSample;

use Illuminate\Pipeline\Pipeline;
use Laragear\Rut\Rut;

class PrintSample extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\GeneratePdfs::class,
    ];

    /**
     * Executes the print sample step for certification using the given RUT.
     */
    public function forRut(Rut $rut): PrintSampleData
    {
        return $this->send(new PrintSampleData($rut))->thenReturn();
    }
}
