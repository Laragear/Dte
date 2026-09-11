<?php

namespace Laragear\Dte\Certification\Simulation\Pipes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Models\SiiDteEnvelope;

class CompileEnvelope
{
    /**
     * Create a new Create Envelope instance.
     */
    public function __construct(
        protected Repository $config,
        protected CreateEnvelope $create,
        protected ConfigurationManager $manager,
    ) {
        //
    }

    /**
     * Handle the incoming simulation data.
     */
    public function handle(SimulationData|TestSetData $data, Closure $next): SimulationData|TestSetData
    {
        $dynamicIssuer = $this->manager->getIssuer($data->rut);

        $senderRut = $data->senderRut
            ?? ($this->manager->hasSenderResolver() ? $this->manager->getSender($data->rut) : $data->rut);

        $envelope = SiiDteEnvelope::create([
            'issuer_rut' => $data->rut,
            'sender_rut' => $senderRut,
            'type' => 'normal',
            'document_type' => $data->dtes->first()->document_type,
            'resolution_date' => $dynamicIssuer->resolutionDate,
            'resolution_number' => $dynamicIssuer->resolutionNumber,
            'status' => EnvelopeStatus::Pending,
        ]);

        // Associate documents
        $data->dtes->each(static function ($dte) use ($envelope): void {
            $dte->envelope()->associate($envelope)->save();
        });

        $envelope->setRelation('dtes', $data->dtes);

        $data->envelope = $this->create->forEnvelope($envelope)->envelope;

        return $next($data);
    }
}
