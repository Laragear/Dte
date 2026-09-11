<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;

class RetrievePendingSiiDte
{
    /**
     * Handle the incoming test set data.
     */
    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        if ($data->dtes->isNotEmpty()) {
            return $next($data);
        }

        $data->dtes = SiiDte::where([
            'issuer_num' => $data->rut->num,
            'issuer_vd' => $data->rut->vd,
        ])
            ->whereIn('status', [
                DteStatus::Pending,
                DteStatus::Signed,
                DteStatus::Sent,
                DteStatus::Accepted,
            ])
            ->when($data->dteIds !== null && $data->dteIds !== [], static function (EloquentBuilder $query) use ($data): void {
                $query->whereIn('id', $data->dteIds);
            })
            ->get();

        if ($data->dtes->isEmpty()) {
            throw new ManuallyFailedException('No eligible DTEs found for the Test Set. Create the DTEs first, or check their status.');
        }

        return $next($data);
    }
}
