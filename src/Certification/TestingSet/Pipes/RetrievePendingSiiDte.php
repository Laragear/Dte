<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Models\SiiDte;

class RetrievePendingSiiDte
{
    /**
     * Handle the incoming test set data.
     */
    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        $data->dtes = SiiDte::where([
            'issuer_num' => $data->rut->num,
            'issuer_vd' => $data->rut->vd,
        ])
            ->when(! empty($data->dteIds), function ($query) use ($data) {
                $query->whereIn('id', $data->dteIds);
            })
            ->get();

        if ($data->dtes->isEmpty()) {
            throw new ManuallyFailedException('No DTEs found to generate the IECV. You need to create the DTEs first.');
        }

        return $next($data);
    }
}
