<?php

namespace Laragear\Dte\Data;

use Laragear\Rut\Rut;

readonly class IssuerData
{
    /**
     * Create a new Issuer Data instance.
     */
    public function __construct(
        public Rut $rut,
        public string $legalName,
        public string $businessActivity,
        public string|array $economicActivity,
        public string $address,
        public string $commune,
        public string $resolutionDate,
        public int $resolutionNumber,
        public ?string $city = null,
        public ?string $telephone = null,
        public ?string $email = null,
        public ?string $branch = null,
    ) {
        //
    }

    /**
     * Create a new instance fluently
     */
    public static function make(
        Rut|string $rut,
        string $legalName,
        string $businessActivity,
        string|array $economicActivity,
        string $address,
        string $commune,
        string $resolutionDate,
        int $resolutionNumber,
        ?string $city = null,
        ?string $telephone = null,
        ?string $email = null,
        ?string $branch = null,
    ): static {
        return new static(
            Rut::parse($rut),
            $legalName,
            $businessActivity,
            $economicActivity,
            $address,
            $commune,
            $resolutionDate,
            $resolutionNumber,
            $city,
            $telephone,
            $email,
            $branch
        );
    }
}
