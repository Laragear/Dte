<?php

namespace Laragear\Dte\Certification\TestingSet;

use Illuminate\Database\Eloquent\Collection;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Rut\Rut;

class TestSetData
{
    /**
     * Create a new Test Set Data instance.
     */
    public function __construct(
        public Rut $rut,
        public array $dteIds = [],
        public Collection $dtes = new Collection,
        public string $period = '',
        public string $resolutionDate = '',
        public int $resolutionNumber = 0,
        public ?Rut $senderRut = null,
        public ?string $iecvXml = null,
        public ?SiiDteEnvelope $envelope = null,
    ) {
        //
    }
}
