<?php

namespace Laragear\Dte\Certification\PrintSample\Pipes\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Laragear\Dte\Models\SiiDte;
use Laragear\Rut\Rut;

trait QueriesLatestDte
{
    /**
     * Returns a query for DTEs by their IDs, or all DTEs for the RUT if no IDs provided.
     */
    protected function query(Rut $rut, array $dteIds = []): Builder
    {
        $query = SiiDte::where([
            'issuer_num' => $rut->num,
            'issuer_vd' => $rut->vd,
        ]);

        if ($dteIds !== []) {
            $query->whereIn('id', $dteIds);
        }

        return $query->latest('id');
    }
}
