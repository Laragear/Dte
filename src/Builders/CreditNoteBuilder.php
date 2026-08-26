<?php

namespace Laragear\Dte\Builders;

use DateTimeImmutable;
use Laragear\Dte\Builders\Concerns\HasCorrections;
use Laragear\Dte\Builders\Concerns\HasItems;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;
use Laragear\Dte\Models\SiiDte;
use LogicException;

class CreditNoteBuilder extends DocumentBuilder
{
    use HasCorrections;
    use HasItems;

    /**
     * Return electronic credit note type 61.
     */
    public function documentType(): DteType
    {
        return DteType::CreditNote;
    }

    /**
     * Discount amounts on a previous document.
     */
    public function discount(
        DteType|ReferenceType|SiiDte|string|int $documentType,
        ?string $folio = null,
        ?DateTimeImmutable $date = null,
        string $reason = 'Corrige montos'
    ): static {
        if ($documentType instanceof SiiDte) {
            $folio = (string) $documentType->folio;
            $date = clone ($documentType->issued_on?->toDateTimeImmutable() ?? $this->date->now('America/Santiago')->toDateTimeImmutable());
            $documentType = $documentType->document_type;
        }

        $this->references = [ReferenceData::make($documentType, $folio, $date, $reason, 3)];

        return $this;
    }

    /**
     * Ensure the credit note identifies the corrected document.
     */
    protected function validateSpecific(): void
    {
        $this->validateB2bReceiver();

        if ($this->references() === []) {
            throw new LogicException('A credit note must contain at least one reference.');
        }
    }
}
