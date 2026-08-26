<?php

namespace Laragear\Dte\Certification\Simulation\Pipes;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Events\EnvelopeSending;
use Laragear\Dte\Events\EnvelopeSent;
use Laragear\Dte\Gateways\UploadGateway;
use Laragear\Dte\SiiEndpoints;

class SendEnvelope
{
    /**
     * Create a new Send Envelope instance.
     */
    public function __construct(protected Dispatcher $event, protected UploadGateway $gateway)
    {
        //
    }

    /**
     * Handle the incoming simulation data.
     */
    public function handle(SimulationData $data, Closure $next): SimulationData
    {
        $envelope = $data->envelope;

        $this->event->dispatch(new EnvelopeSending($envelope));

        $trackId = $this->gateway->upload($envelope, $envelope->payload->xml, SiiEndpoints::SOAP_CERTIFICATION);

        $envelope->update([
            'track_id' => $trackId,
            'status' => EnvelopeStatus::Uploaded,
        ]);

        $this->event->dispatch(new EnvelopeSent($envelope));

        return $next($data);
    }
}
