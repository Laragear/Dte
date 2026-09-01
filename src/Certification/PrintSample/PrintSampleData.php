<?php

namespace Laragear\Dte\Certification\PrintSample;

use Illuminate\Database\Eloquent\Collection;
use Laragear\Dte\Models\SiiDte;
use Laragear\Rut\Rut;

class PrintSampleData
{
    /**
     * Create a new Print Sample data instance.
     *
     * @param  Collection<int, SiiDte>|null  $dtes
     * @param  array<string, string>  $pdfs
     */
    public function __construct(
        public Rut $rut,
        public int $hours = 24,
        public ?Collection $dtes = null,
        public array $pdfs = []
    ) {
        $this->dtes ??= new Collection;
    }
}
