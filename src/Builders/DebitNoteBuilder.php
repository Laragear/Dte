<?php

namespace Laragear\Dte\Builders;

use DateTimeImmutable;
use Laragear\Dte\Builders\Concerns\HasCorrections;
use Laragear\Dte\Builders\Concerns\HasItems;
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
     * Charge amounts to a previous document.
     */
    public function charge(
        DteType|ReferenceType|SiiDte|string|int $documentType,
        ?string $folio = null,
        ?DateTimeImmutable $date = null,
        string $reason = 'Corrige montos'
    ): static {
        return $this->modify($documentType, $folio, $date, $reason);
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
