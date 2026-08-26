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

class DebitNoteBuilder extends DocumentBuilder
{
    use HasCorrections;
    use HasItems;

    /**
     * Return electronic debit note type 56.
     */
    public function documentType(): DteType
    {
        return DteType::DebitNote;
    }

    /**
     * Charge amounts on a previous document.
     */
    public function charge(
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
     * Ensure the debit note identifies the adjusted document.
     */
    protected function validateSpecific(): void
    {
        $this->validateB2bReceiver();

        if ($this->references() === []) {
            throw new LogicException('A debit note must contain at least one reference.');
        }
    }
}
