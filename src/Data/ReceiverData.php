<?php

namespace Laragear\Dte\Data;

use Laragear\Rut\Rut;
use function json_decode;

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

    /**
     * Create a new instance from an array.
     */
    public static function fromArray(array $array): static
    {
        return static::make(
            $array['rut'],
            $array['legal_name'],
            $array['business_activity'] ?? null,
            $array['email'] ?? null,
            $array['address'] ?? null,
            $array['commune'] ?? null,
            $array['city'] ?? null,
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
