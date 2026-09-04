<?php

namespace Laragear\Dte\Certification\PrintSample;

use Laragear\Rut\Rut;

class PrintSampleData
{
    /**
     * Create a new Print Sample data instance.
     *
     * @param  int[]  $dteIds
     * @param  array<string, string>  $pdfs
     */
    public function __construct(
        public Rut $rut,
        public array $dteIds = [],
        public array $pdfs = [],
    ) {
        //
    }
}
