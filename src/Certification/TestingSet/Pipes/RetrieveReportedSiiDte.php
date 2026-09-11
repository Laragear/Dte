<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use function filled;

class RetrieveReportedSiiDte
{
    /**
     * Retrieve only DTEs that have been reported for review (envelope-associated, sent/accepted).
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
            ->whereNotNull('sii_dte_envelope_id')
            ->whereIn('status', [
                DteStatus::Sent,
                DteStatus::Accepted,
            ])
            ->when($data->dteIds, static function (EloquentBuilder $query, array $ids): void {
                $query->whereIn('id', $ids);
            })
            ->get();

        if ($data->dtes->isEmpty()) {
            throw new ManuallyFailedException(
                'No reported DTEs found. Send the Test Set envelope first with CertificationManager::testSet().',
            );
        }

        return $next($data);
    }
}
