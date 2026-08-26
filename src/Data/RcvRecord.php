<?php

namespace Laragear\Dte\Data;

use Illuminate\Support\Carbon;
use Laragear\Dte\Enums\DteType;
use Laragear\Rut\Rut;

class RcvRecord
{
    /**
     * Create a new RCV Record instance.
     */
    public function __construct(
        public readonly Rut $issuer,
        public readonly Rut $receiver,
        public readonly DteType $documentType,
        public readonly int $folio,
        public readonly int $amountTotal,
        public readonly string $characterization,
        public readonly ?Carbon $issuedOn = null,
        public readonly ?Carbon $acknowledgedAt = null,
    ) {}
}
