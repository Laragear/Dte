<?php

namespace Laragear\Dte\Data;

use Laragear\Rut\Rut;

readonly class ReceiverData
{
    /**
     * Create a new Receiver Data instance.
     */
    public function __construct(
        public Rut $rut,
        public string $legalName,
        public ?string $businessActivity = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $commune = null,
        public ?string $city = null,
    ) {
        //
    }

    /**
     * Create a new instance fluently
     */
    public static function make(
        Rut|string $rut,
        string $legalName,
        ?string $businessActivity = null,
        ?string $email = null,
        ?string $address = null,
        ?string $commune = null,
        ?string $city = null,
    ): static {
        return new static(Rut::parse($rut), $legalName, $businessActivity, $email, $address, $commune, $city);
    }
}
