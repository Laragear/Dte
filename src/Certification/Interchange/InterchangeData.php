<?php

namespace Laragear\Dte\Certification\Interchange;

use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Rut\Rut;

class InterchangeData
{
    /**
     * Create a new Interchange Data instance.
     */
    public function __construct(
        public Rut $rut,
        public string $source = 'mailbox',
        public ?string $filePath = null,
        public ?string $xmlContent = null,
        public ?Rut $signerRut = null,
        public ?string $location = null,
        public ?SiiInboundDocument $inboundDocument = null,
        public ?InboundEmailData $emailData = null,
    ) {
        //
    }
}
