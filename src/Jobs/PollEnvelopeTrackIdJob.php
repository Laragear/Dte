<?php

namespace Laragear\Dte\Jobs;

use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Str;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Events\DteAccepted;
use Laragear\Dte\Events\DteRejected;
use Laragear\Dte\Events\EnvelopeAccepted;
use Laragear\Dte\Events\EnvelopeRejected;
use Laragear\Dte\Gateways\BoletaRestGateway;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\TokenAuthenticator;
use Laragear\Dte\Support\XmlDomFactory as Xml;
use Psr\Log\LoggerInterface;

#[Backoff([30, 60, 120, 300, 600])]
#[Tries(5)]
#[Timeout(120)]
class PollEnvelopeTrackIdJob implements ShouldQueue
{
    use Queueable;

    /**
     * Config key toggling the automatic interchange submission after acceptance.
     */
    protected const string AUTO_SEND_INTERCHANGE_CONFIG = 'dte.dim.auto_send_interchange';

    /**
     * The token authenticator, assigned at handle time (not serialized).
     */
    protected TokenAuthenticator $authenticator;

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
        TokenAuthenticator $authenticator,
        ConfigRepository $config,
        Xml $factory,
        DateFactory $date,
    ): void {
        $this->authenticator = $authenticator;

        if ($this->shouldNotPoll()) {
            return;
        }

        try {
            if ($this->envelope->type === 'boleta') {
                $status = $boletaGateway->trackStatus($this->envelope);
                $this->processBoletaTrackIdStatus($status, $event, $config, $log, $date);
            } else {
                $this->processTrackIdStatus($this->queryTrackIdStatus($gateway), $event, $config, $log, $factory,
                    $date);
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
        LoggerInterface $log,
        DateFactory $date,
    ): void {
        $estado = $status['estado'] ?? null;

        if ($estado === 'EPR') {
            $this->handleBoletaAccepted($event, $config, $date, $status);
        } elseif (in_array($estado, ['RCH', 'RCO', 'VOF', 'RFR', 'RPT', 'REC'], true)) {
            $this->handleRejected($event, $config, $date);
        } elseif (in_array($estado, ['CRT', 'FOK', 'PRD', 'SOK'], true)) {
            $this->handleProcessing($config, $date);
        } else {
            $log->warning(
                "Unknown SII boleta track ID status received for track ID {$this->envelope->track_id}: ".
                json_encode($status)
            );
        }
    }

    /**
     * Handles the accepted boleta envelope status and parses DTE rejections if any.
     */
    protected function handleBoletaAccepted(
        Dispatcher $event,
        ConfigRepository $config,
        DateFactory $date,
        array $status
    ): void {
        $this->parseBoletaProcessedEnvelopeDtes($event, $date, $status);

        $this->envelope->status = EnvelopeStatus::Accepted;
        $this->envelope->accepted_at = $date->now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeAccepted($this->envelope));

        if ($config->get(static::AUTO_SEND_INTERCHANGE_CONFIG, true)) {
            SendInterchangeEnvelopeJob::dispatch($this->envelope);
        }
    }

    /**
     * Parses the Boleta JSON response to check if any DTE was rejected or repaired.
     */
    protected function parseBoletaProcessedEnvelopeDtes(Dispatcher $event, DateFactory $date, array $status): void
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
            $this->updateEnvelopeDtes($date, $event);
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
     *
     * Uses the authenticator's retryWithFreshToken() loop: on
     * TokenInvalidException (SII returned 001/002/003), the authenticator
     * refreshes the token and retries — up to 3 total attempts before the
     * exception propagates and the queue backoff handles the delay.
     */
    protected function queryTrackIdStatus(SoapGateway $gateway): string
    {
        $issuer = $this->envelope->issuer_rut;

        return $this->authenticator->retryWithFreshToken(function () use ($gateway, $issuer): string {
            $token = $this->authenticator->token($issuer);

            $response = $gateway->query($token, 'QueryEstUp', 'getEstUp', [
                'RutCompany' => $issuer->num,
                'DvCompany' => $issuer->vd,
                'TrackId' => $this->envelope->track_id,
                'Token' => $token->value,
            ]);

            $xml = is_object($response) ? $response->getEstUpResult ?? '' : (string) $response;

            // SII returns 001/002/003 for an invalid token — signal the trait to refresh.
            if ($this->isTokenInvalidStatus($xml)) {
                throw new TokenInvalidException('SII SOAP token was invalidated (001/002/003).');
            }

            return $xml;
        }, $issuer);
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
        DateFactory $date,
    ): void {
        if (Str::contains($xml, '<ESTADO>EPR</ESTADO>')) {
            $this->handleAccepted($event, $config, $factory, $date, $xml);
        } elseif ($this->isRejectedStatus($xml)) {
            $this->handleRejected($event, $config, $date);
        } elseif ($this->isProcessingStatus($xml)) {
            $this->handleProcessing($config, $date);
        } else {
            $log->warning("Unknown SII track ID status received for track ID {$this->envelope->track_id}: ".$xml);
        }
    }

    /**
     * Checks if the XML response indicates an invalid/expired SOAP token.
     *
     * SII returns 001 (inactive), 002 (invalid) or 003 (invalid) for a token that
     * must be refreshed by re-authenticating.
     */
    protected function isTokenInvalidStatus(string $xml): bool
    {
        return Str::contains($xml, [
            '<ESTADO>001</ESTADO>',
            '<ESTADO>002</ESTADO>',
            '<ESTADO>003</ESTADO>',
        ]);
    }

    /**
     * Handles the accepted envelope status and parses DTE rejections if any.
     */
    protected function handleAccepted(
        Dispatcher $event,
        ConfigRepository $config,
        Xml $factory,
        DateFactory $date,
        string $xml
    ): void {
        $this->parseProcessedEnvelopeDtes($event, $factory, $date, $xml);

        $this->envelope->status = EnvelopeStatus::Accepted;
        $this->envelope->accepted_at = $date->now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeAccepted($this->envelope));

        if ($config->get(static::AUTO_SEND_INTERCHANGE_CONFIG, true)) {
            SendInterchangeEnvelopeJob::dispatch($this->envelope);
        }
    }

    /**
     * Parses the EPR XML response to check if any DTE was rejected or repaired.
     */
    protected function parseProcessedEnvelopeDtes(Dispatcher $event, Xml $factory, DateFactory $date, string $xml): void
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
            $this->updateEnvelopeDtes($date, $event);
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
    protected function handleRejected(Dispatcher $event, ConfigRepository $config, DateFactory $date): void
    {
        $this->envelope->status = EnvelopeStatus::Rejected;
        $this->envelope->rejected_at = $date->now();
        $this->envelope->save();

        $event->dispatch(new EnvelopeRejected($this->envelope));

        $this->releaseOrRejectAllDtes($event, $config, $date);
    }

    /**
     * Releases all DTEs from the structurally rejected envelope for re-packing, or rejects them if max retries reached.
     */
    protected function releaseOrRejectAllDtes(Dispatcher $event, ConfigRepository $config, DateFactory $date): void
    {
        $maxRetries = $config->get('dte.envelopes.max_retries', 3);
        $now = $date->now();

        $retryable = $this->envelope->dtes->filter(fn(SiiDte $dte): bool => $dte->pack_retries < $maxRetries);
        $rejected = $this->envelope->dtes->filter(fn(SiiDte $dte): bool => $dte->pack_retries >= $maxRetries);

        if ($retryable->isNotEmpty()) {
            $this->envelope->dtes()
                ->whereIn('id', $retryable->modelKeys())
                ->increment('pack_retries', 1, [
                    'sii_dte_envelope_id' => null,
                    'status' => DteStatus::Signed,
                    'updated_at' => $now,
                ]);
        }

        if ($rejected->isNotEmpty()) {
            $this->envelope->dtes()
                ->whereIn('id', $rejected->modelKeys())
                ->update([
                    'status' => DteStatus::Rejected,
                    'rejected_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        foreach ($retryable as $dte) {
            $dte->forceFill([
                'pack_retries' => $dte->pack_retries + 1,
                'sii_dte_envelope_id' => null,
                'status' => DteStatus::Signed,
                'updated_at' => $now,
            ]);
            $dte->syncOriginal();
        }

        foreach ($rejected as $dte) {
            $dte->forceFill([
                'status' => DteStatus::Rejected,
                'rejected_at' => $now,
                'updated_at' => $now,
            ]);
            $dte->syncOriginal();

            $event->dispatch(new DteRejected($dte));
        }
    }

    /**
     * Handles the processing envelope status by resetting the polling timer and
     * re-querying SII after the mandated minimum delay for this envelope size.
     */
    protected function handleProcessing(ConfigRepository $config, DateFactory $date): void
    {
        // Touch resets updated_at, preventing Cron from re-polling during the holding period.
        $this->envelope->touch();

        // Re-query SII after the mandatory floor (2 or 6 minutes) plus any configured
        // offset, so every subsequent poll respects the SII size-based minimum.
        self::dispatch($this->envelope)->delay($date->now()->addSeconds($this->effectiveDelay($config,
            $this->envelopeSizeBytes())));
    }

    /**
     * Computes the minimum delay before the next status poll for a payload of the
     * given size in bytes. SII mandates at least 120 seconds for envelopes under
     * 30 KB and 360 seconds for envelopes of 30 KB or more.
     *
     * @param  ConfigRepository  $config
     * @param  int  $envelopeSizeBytes
     * @return int
     */
    protected function effectiveDelay(ConfigRepository $config, int $envelopeSizeBytes): int
    {
        if ($envelopeSizeBytes < 30 * 1024) {
            return 120 + max(0, (int) $config->get('dte.polling.delay_under_30kb', 0));
        }

        return 360 + max(0, (int) $config->get('dte.polling.delay_over_30kb', 0));
    }

    /**
     * Returns the size in bytes of the uploaded envelope XML. When the payload is
     * not available, assumes the larger (30 KB) bucket so the delay is never
     * shorter than SII actually requires.
     *
     * @return int
     */
    protected function envelopeSizeBytes(): int
    {
        if ($this->envelope->relationLoaded('payload') && $this->envelope->payload?->xml) {
            return strlen($this->envelope->payload->xml);
        }

        // Conservative: default to the >= 30 KB bucket (360s floor).
        return 30 * 1024;
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

    /**
     * Updates the DTE contained in the envelope.
     */
    protected function updateEnvelopeDtes(DateFactory $date, Dispatcher $event): void
    {
        $now = $date->now();

        // Only issue a single `UPDATE` to the database.
        $this->envelope->dtes()->update([
            'status' => DteStatus::Accepted->value,
            'accepted_at' => $now,
            'updated_at' => $now,
        ]);

        // Force update and sync each model and dispatch the event.
        foreach ($this->envelope->dtes as $dte) {
            $dte->forceFill([
                'status' => DteStatus::Accepted,
                'accepted_at' => $now,
                'updated_at' => $now,
            ]);
            $dte->syncOriginal();

            $event->dispatch(new DteAccepted($dte));
        }
    }
}
