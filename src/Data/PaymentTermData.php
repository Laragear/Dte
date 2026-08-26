<?php

namespace Laragear\Dte\Data;

use DateTimeImmutable;

readonly class PaymentTermData
{
    /**
     * Create a new Payment Term Data instance.
     */
    public function __construct(
        public string $condition,
        public DateTimeImmutable $expirationDate,
    ) {
        //
    }

    /**
     * Create a new instance fluently
     */
    public static function make(
        string $condition,
        DateTimeImmutable $expirationDate,
    ): static {
        return new static($condition, $expirationDate);
    }
}
