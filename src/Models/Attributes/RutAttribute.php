<?php

namespace Laragear\Dte\Models\Attributes;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Laragear\Rut\Rut;

final class RutAttribute
{
    /**
     * Create a RUT attribute backed by split number and verification-digit columns.
     *
     * @return Attribute<Rut, Rut|int|string>
     */
    public static function make(string $prefix): Attribute
    {
        return Attribute::make(
            get: static fn(mixed $value, array $attributes): Rut => new Rut(
                $attributes["{$prefix}_num"],
                $attributes["{$prefix}_vd"],
            ),
            set: static fn(Rut|int|string $value): array => self::columns($prefix, Rut::parse($value)),
        );
    }

    /**
     * Return the split database columns for a RUT.
     *
     * @return array<string, int|string>
     */
    protected static function columns(string $prefix, Rut $rut): array
    {
        return ["{$prefix}_num" => $rut->num, "{$prefix}_vd" => $rut->vd];
    }
}
