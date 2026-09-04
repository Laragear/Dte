<?php

namespace Laragear\Dte\Certification\Simulation;

use Illuminate\Database\Eloquent\Collection;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Rut\Rut;

class SimulationData
{
    /**
     * Create a new Simulation Data instance.
     *
     * @param  int[]|DteType[]  $documentTypes
     */
    public function __construct(
        public Rut $rut,
        public int $quantity = 10,
        public array $documentTypes = [],
        public Collection $dtes = new Collection,
        public ?Rut $senderRut = null,
        public ?SiiDteEnvelope $envelope = null,
    ) {
        $this->documentTypes = array_map(
            fn(int|DteType $type): int => $type instanceof DteType ? $type->value : $type,
            $documentTypes,
        );
    }
}
