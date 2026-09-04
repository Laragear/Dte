<?php

namespace Laragear\Dte\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Laragear\Dte\Enums\DteType;

/**
 * @method Builder<static>|static whereDocumentType(DteType $type)
 */
trait HasDocumentType
{
    /**
     * Local scope to filter records by DTE type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeWhereDocumentType(Builder $query, DteType $type): Builder
    {
        return $query->where('document_type', $type->value);
    }
}
