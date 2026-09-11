<?php

namespace Laragear\Dte\Data;

use DateTimeImmutable;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;
use function is_int;
use function json_decode;

readonly class ReferenceData
{
    /**
     * Create a new Reference Data instance.
     */
    public function __construct(
        public DteType|ReferenceType $documentType,
        public ?string $folio,
        public ?DateTimeImmutable $date,
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
        ?string $folio,
        DateTimeImmutable|string|null $date,
        ?string $reason = null,
        ?int $referenceCode = null,
    ): static {
        $date = match (true) {
            $date instanceof DateTimeImmutable => $date,
            is_string($date) => DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: null,
            default => null,
        };

        $documentType = match (true) {
            $documentType instanceof DteType, $documentType instanceof ReferenceType => $documentType,
            default => DteType::tryFrom($documentType) ?? ReferenceType::from($documentType),
        };

        return new static($documentType, $folio, $date, $reason, $referenceCode);
    }

    /**
     * Create a new instance from an array.
     */
    public static function fromArray(array $array): static
    {
        return static::make(
            $array['document_type'],
            $array['folio'] ?? null,
            $array['date'] ?? null,
            $array['reason'] ?? null,
            $array['reference_code' ?? null],
        );
    }

    /**
     * Create a new instance from a JSON string.
     */
    public static function fromJson(string $json): static
    {
        return static::fromArray(json_decode($json, true, 1));
    }
}
