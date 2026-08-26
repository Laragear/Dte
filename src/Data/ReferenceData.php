<?php

namespace Laragear\Dte\Data;

use DateTimeImmutable;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;

readonly class ReferenceData
{
    /**
     * Create a new Reference Data instance.
     */
    public function __construct(
        public DteType|ReferenceType|string|int $documentType,
        public string $folio,
        public DateTimeImmutable $date,
        public ?string $reason = null,
        public ?int $referenceCode = null,
    ) {
        //
    }

    /**
     * Create a new instance fluently
     */
    public static function make(
        DteType|ReferenceType|string|int $documentType,
        string $folio,
        DateTimeImmutable $date,
        ?string $reason = null,
        ?int $referenceCode = null,
    ): static {
        return new static($documentType, $folio, $date, $reason, $referenceCode);
    }
}
