<?php

namespace Laragear\Dte\Jobs;

use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Events\DteAccepted;
use Laragear\Dte\Events\DteRejected;
use Laragear\Dte\Events\EnvelopeAccepted;
use Laragear\Dte\Events\EnvelopeRejected;
use Laragear\Dte\Gateways\BoletaRestGateway;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\XmlDomFactory as Xml;
use Psr\Log\LoggerInterface;

class PollEnvelopeTrackIdJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SiiDteEnvelope $envelope,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        LoggerInterface $log,
        Dispatcher $event,
        SoapGateway $gateway,
        BoletaRestGateway $boletaGateway,
        ConfigRepository $config,
        Xml $factory,
    ): void {
        if ($this->shouldNotPoll()) {
            return;
        }

        try {
            if ($this->envelope->type === 'boleta') {
                $status = $boletaGateway->trackStatus($this->envelope);
                $this->processBoletaTrackIdStatus($status, $event, $config, $log);
            } else {
                $this->processTrackIdStatus($this->queryTrackIdStatus($gateway), $event, $config, $log, $factory);
            }
        } catch (Exception $e) {
            $log->error("Failed to poll TrackID {$this->envelope->track_id}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Processes the SII boleta track ID status response.
     */
    protected function processBoletaTrackIdStatus(
        array $status,
        Dispatcher $event,
        ConfigRepository $config,
        LoggerInterface $log
    ): void {
        $estado = $status['estado'] ?? null;

        if ($estado === 'EPR') {
            $this->handleBoletaAccepted($event, $config, $status);
        } elseif (in_array($estado, ['RCH', 'RCO', 'VOF', 'RFR', 'RPT'], true)) {
            $this->handleRejected($event, $config);
        } elseif (in_array($estado, ['REC', 'CRT', 'FOK', 'PRD', 'SOK'], true)) {
            $this->handleProcessing();
        } else {
            $log->warning("Unknown SII boleta track ID status received for track ID {$this->envelope->track_id}: ".json_encode($status));
        }
    }

    /**
     * Handles the accepted boleta envelope status and parses DTE rejections if any.
     */
    protected function handleBoletaAccepted(Dispatcher $event, ConfigRepository $config, array $status): void
    {
        $this->parseBoletaProcessedEnvelopeDtes($status, $event);

        $this->envelope->status = EnvelopeStatus::Accepted;
        $this->envelope->accepted_at = now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeAccepted($this->envelope));

        if ($config->get('dte.dim.auto_send_interchange', true)) {
            SendInterchangeEnvelopeJob::dispatch($this->envelope);
        }
    }

    /**
     * Parses the Boleta JSON response to check if any DTE was rejected or repaired.
     */
    protected function parseBoletaProcessedEnvelopeDtes(array $status, Dispatcher $event): void
    {
        $rechazados = 0;
        $reparos = 0;

        foreach ($status['estadistica'] ?? [] as $stat) {
            $rechazados += (int) ($stat['rechazados'] ?? 0);
            $reparos += (int) ($stat['reparos'] ?? 0);
        }

        if ($rechazados > 0 || $reparos > 0) {
            $this->saveEnvelopeRepairs($status, json_encode($status));

            foreach ($this->envelope->dtes as $dte) {
                PollDteStatusJob::dispatch($dte);
            }
        } else {
            foreach ($this->envelope->dtes as $dte) {
                $dte->status = DteStatus::Accepted;
                $dte->accepted_at = now();
                $dte->save();

                $event->dispatch(new DteAccepted($dte));
            }
        }
    }

    /**
     * Checks if this envelope should not be polled.
     */
    protected function shouldNotPoll(): bool
    {
        return $this->envelope->status !== EnvelopeStatus::Uploaded
            || empty($this->envelope->track_id);
    }

    /**
     * Queries the SII for the current status of the track ID.
     */
    protected function queryTrackIdStatus(SoapGateway $gateway): string
    {
        $issuer = $this->envelope->issuer_rut;
        $token = $gateway->token($issuer);

        $response = $gateway->query($issuer, 'QueryEstUp', 'getEstUp', [
            'RutCompany' => $issuer->num,
            'DvCompany' => $issuer->vd,
            'TrackId' => $this->envelope->track_id,
            'Token' => $token->value,
        ]);

        // Depending on ext-soap or Proxy, the response could be an object or string
        return is_object($response) ? $response->getEstUpResult ?? '' : (string) $response;
    }

    /**
     * Processes the SII track ID status response.
     */
    protected function processTrackIdStatus(
        string $xml,
        Dispatcher $event,
        ConfigRepository $config,
        LoggerInterface $log,
        Xml $factory,
    ): void {
        if (Str::contains($xml, '<ESTADO>EPR</ESTADO>')) {
            $this->handleAccepted($event, $config, $factory, $xml);
        } elseif ($this->isRejectedStatus($xml)) {
            $this->handleRejected($event, $config);
        } elseif ($this->isProcessingStatus($xml)) {
            $this->handleProcessing();
        } else {
            $log->warning("Unknown SII track ID status received for track ID {$this->envelope->track_id}: ".$xml);
        }
    }

    /**
     * Handles the accepted envelope status and parses DTE rejections if any.
     */
    protected function handleAccepted(Dispatcher $event, ConfigRepository $config, Xml $factory, string $xml): void
    {
        $this->parseProcessedEnvelopeDtes($xml, $event, $factory);

        $this->envelope->status = EnvelopeStatus::Accepted;
        $this->envelope->accepted_at = now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeAccepted($this->envelope));

        if ($config->get('dte.dim.auto_send_interchange', true)) {
            SendInterchangeEnvelopeJob::dispatch($this->envelope);
        }
    }

    /**
     * Parses the EPR XML response to check if any DTE was rejected or repaired.
     */
    protected function parseProcessedEnvelopeDtes(string $xml, Dispatcher $event, Xml $factory): void
    {
        $simple = $factory->simpleXml($xml);

        $body = $simple->children('SII', true)->RESP_BODY ?? null;

        if (!$body) {
            return; // No details provided by SII
        }

        // If there are rejections or reparos, we query the state of every DTE individually
        $rechazados = (int) ($body->children()->RECHAZADOS ?? 0);
        $reparos = (int) ($body->children()->REPAROS ?? 0);

        if ($rechazados > 0 || $reparos > 0) {
            $friendly = json_decode(json_encode($simple), true);
            $this->saveEnvelopeRepairs($friendly, $xml);

            foreach ($this->envelope->dtes as $dte) {
                // Dispatch a job to query the specific DTE status from SII
                // We'll create a PollDteStatusJob for this!
                PollDteStatusJob::dispatch($dte);
            }
        } else {
            // All DTEs accepted
            foreach ($this->envelope->dtes as $dte) {
                $dte->status = DteStatus::Accepted;
                $dte->accepted_at = now();
                $dte->save();

                $event->dispatch(new DteAccepted($dte));
            }
        }
    }

    /**
     * Saves the SII repairs and the raw response to the envelope and its payload.
     */
    protected function saveEnvelopeRepairs(array $friendly, string $raw): void
    {
        $this->envelope->repairs = $friendly;

        if ($this->envelope->relationLoaded('payload') || $this->envelope->payload) {
            $this->envelope->payload->update(['sii_response' => $raw]);
        }
    }

    /**
     * Handles the rejected envelope status.
     */
    protected function handleRejected(Dispatcher $event, ConfigRepository $config): void
    {
        $this->envelope->status = EnvelopeStatus::Rejected;
        $this->envelope->rejected_at = now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeRejected($this->envelope));

        $this->releaseOrRejectAllDtes($event, $config);
    }

    /**
     * Releases all DTEs from the structurally rejected envelope for re-packing, or rejects them if max retries reached.
     */
    protected function releaseOrRejectAllDtes(Dispatcher $event, ConfigRepository $config): void
    {
        $maxRetries = $config->get('dte.envelopes.max_retries', 3);

        foreach ($this->envelope->dtes as $dte) {
            if ($dte->pack_retries < $maxRetries) {
                $dte->pack_retries++;
                $dte->sii_dte_envelope_id = null;
                $dte->status = DteStatus::Signed; // Ready to be packed again
                $dte->save();
            } else {
                $dte->status = DteStatus::Rejected;
                $dte->rejected_at = now();
                $dte->save();

                $event->dispatch(new DteRejected($dte));
            }
        }
    }

    /**
     * Handles the processing envelope status by resetting the polling timer.
     */
    protected function handleProcessing(): void
    {
        // Touch resets updated_at, preventing Cron from re-polling during the holding period.
        $this->envelope->touch();
    }

    /**
     * Checks if the XML response contains a rejected status code.
     */
    protected function isRejectedStatus(string $xml): bool
    {
        return Str::contains($xml, [
            '<ESTADO>RSC</ESTADO>',
            '<ESTADO>RCT</ESTADO>',
            '<ESTADO>REC</ESTADO>',
            '<ESTADO>RCH</ESTADO>',
            '<ESTADO>RPR</ESTADO>',
            '<ESTADO>RFR</ESTADO>',
        ]);
    }

    /**
     * Checks if the XML response contains a processing status code.
     */
    protected function isProcessingStatus(string $xml): bool
    {
        return Str::contains($xml, [
            '<ESTADO>PRD</ESTADO>',
            '<ESTADO>SOK</ESTADO>',
            '<ESTADO>CRT</ESTADO>',
        ]);
    }
}
