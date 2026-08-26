<?php

namespace Laragear\Dte\Certification\Simulation\Pipes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDteEnvelope;

class CompileEnvelope
{
    /**
     * Create a new Create Envelope instance.
     */
    public function __construct(
        protected Repository $config,
        protected CreateEnvelope $create,
    ) {
        //
    }

    /**
     * Handle the incoming simulation data.
     */
    public function handle(SimulationData $data, Closure $next): SimulationData
    {
        $configManager = app(ConfigurationManager::class);
        $dynamicIssuer = $configManager->getIssuer($data->rut);

        $envelope = SiiDteEnvelope::create([
            'issuer_rut' => $data->rut,
            'sender_rut' => $data->senderRut ?? $data->rut,
            'type' => 'normal',
            'document_type' => DteType::DEFAULT,
            'resolution_date' => $dynamicIssuer?->resolutionDate ?? $this->config->get(
                'dte.issuer.resolution_date',
                '2023-01-01',
            ),
            'resolution_number' => $dynamicIssuer?->resolutionNumber ?? $this->config->get(
                'dte.issuer.resolution_number',
                0,
            ),
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
