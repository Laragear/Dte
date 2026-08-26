<?php

namespace Laragear\Dte\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laragear\Dte\Caf\Folio;

class AsFolio implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  Model  $model
     * @param  mixed  $value
     */
    public function get($model, string $key, $value, array $attributes): Folio
    {
        return new Folio(
            (int) $attributes['folio_from'],
            (int) $attributes['folio_to'],
            (int) $attributes['folio_current'],
            json_decode($attributes['folio_annuled'] ?? '[]', true)
        );
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  Model  $model
     * @param  mixed  $value
     */
    public function set($model, string $key, $value, array $attributes): array
    {
        if (! $value instanceof Folio) {
            throw new InvalidArgumentException('The given value is not a Folio instance.');
        }

        return [
            'folio_from' => $value->from,
            'folio_to' => $value->to,
            'folio_current' => $value->current,
            'folio_annuled' => json_encode($value->annuled),
        ];
    }
}
