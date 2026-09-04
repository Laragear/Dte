<?php

namespace Laragear\Dte\Actions\PersistDte\Pipes;

use Closure;
use Laragear\Dte\Actions\PersistDte\DteData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;

class PersistDocument
{
    /**
     * Handle the incoming DTE Persistence.
     *
     * @param  Closure(DteData):DteData  $next
     */
    public function handle(DteData $data, Closure $next): DteData
    {
        $data->dte = $data->isUpdate
            ? $this->persistUpdate($data)
            : $this->persistCreate($data);

        return $next($data);
    }

    /**
     * Creates a new model on the database.
     */
    protected function persistCreate(DteData $data): SiiDte
    {
        $dte = SiiDte::create($data->attributes);

        $payload = $dte->payload()->create(['data' => $data->payloadData]);

        $dte->setRelation('payload', $payload);

        return $dte;
    }

    /**
     * Updates an existing model in the database,
     */
    protected function persistUpdate(DteData $data): SiiDte
    {
        $dte = $data->builder->dte();

        $dte->forceFill(array_merge($data->attributes, [
            'status' => DteStatus::Pending,
            'repairs' => null,
            'acknowledged_at' => null,
            'accepted_at' => null,
            'rejected_at' => null,
        ]))->save();

        $payload = $dte->payload()->updateOrCreate([], [
            'data' => $data->payloadData,
            'xml' => null,
            'sii_response' => null,
        ]);

        $dte->setRelation('payload', $payload);

        return $dte;
    }
}
