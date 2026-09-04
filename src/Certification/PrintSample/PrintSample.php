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
        Pipes\EnsureDteExist::class,
        Pipes\GeneratePdfs::class,
    ];
}
