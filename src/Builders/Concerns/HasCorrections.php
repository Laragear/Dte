<?php

namespace Laragear\Dte\Builders\Concerns;

use DateTimeImmutable;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;
use Laragear\Dte\Models\SiiDte;

trait HasCorrections
{
    use HasReferences;

    /**
     * Annul (cancel) a previous document.
     */
    public function annul(
        DteType|ReferenceType|SiiDte|string|int $documentType,
        ?string $folio = null,
        ?DateTimeImmutable $date = null,
        string $reason = 'Anula documento'
    ): static {
        if ($documentType instanceof SiiDte) {
            [$documentType, $folio, $date] = $this->extractFromDte($documentType);
        }

        $this->references = [ReferenceData::make($documentType, $folio, $date, $reason, 1)];

        return $this;
    }

    /**
     * Amend (correct text) on a previous document.
     */
    public function amend(
        DteType|ReferenceType|SiiDte|string|int $documentType,
        ?string $folio = null,
        ?DateTimeImmutable $date = null,
        string $reason = 'Corrige texto'
    ): static {
        if ($documentType instanceof SiiDte) {
            [$documentType, $folio, $date] = $this->extractFromDte($documentType);
        }

        $this->references = [ReferenceData::make($documentType, $folio, $date, $reason, 2)];

        return $this;
    }

    /**
     * Extract document type, folio, and date from a model instance.
     *
     * @return array{0: DteType, 1: string, 2: DateTimeImmutable}
     */
    protected function extractFromDte(SiiDte $dte): array
    {
        return [
            $dte->document_type,
            (string) $dte->folio,
            clone ($dte->issued_on?->toDateTimeImmutable() ?? $this->date->now('America/Santiago')->toDateTimeImmutable()),
        ];
    }
}
