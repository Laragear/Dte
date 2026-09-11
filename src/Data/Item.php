<?php

namespace Laragear\Dte\Data;

use function json_decode;

readonly class Item
{
    /**
     * Create a new Item Data instance.
     *
     * @param  array<string, int>  $taxes  Dictionary of tax codes to their applied amounts on this item
     */
    public function __construct(
        public string $name,
        public float $unitPrice,
        public float $quantity = 1.0,
        public ?string $description = null,
        public ?string $unit = null,
        public ?string $code = null,
        public ?string $codeType = null,
        public float $discountPercentage = 0.0,
        public bool $exempt = false,
        public array $taxes = [],
    ) {
        //
    }

    /**
     * Create a new instance fluently
     *
     * @param  array<string, int>  $taxes
     */
    public static function make(
        string $name,
        float $unitPrice,
        float $quantity = 1.0,
        ?string $description = null,
        ?string $unit = null,
        ?string $code = null,
        ?string $codeType = null,
        float $discountPercentage = 0.0,
        bool $exempt = false,
        array $taxes = [],
    ): static {
        return new static(
            $name,
            $unitPrice,
            $quantity,
            $description,
            $unit,
            $code,
            $codeType,
            $discountPercentage,
            $exempt,
            $taxes,
        );
    }

    /**
     * Create a new instance from an array.
     */
    public static function fromArray(array $array): static
    {
        return static::make(
            $array['name'],
            $array['unit_price'],
            $array['quantity'],
            $array['description'] ?? null,
            $array['unit'] ?? null,
            $array['code'] ?? null,
            $array['code_type'] ?? null,
            $array['discount_percentage'] ?? 0,
            $array['exempt'] ?? false,
            $array['taxes'] ?? [],
        );
    }

    /**
     * Create a new instance from a JSON string.
     */
    public static function fromJson(string $json): static
    {
        return static::fromArray(json_decode($json, true, 5));
    }
}
