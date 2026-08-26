<?php

namespace Laragear\Dte\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Rut\Rut;

use function in_array;

class PackDtesService
{
    /**
     * Create a new Pack DTEs Service instance.
     */
    public function __construct(
        protected Kernel $artisan,
        protected Repository $config,
        protected DateFactory $date,
        protected ConfigurationManager $configManager,
    ) {
        //
    }

    /**
     * Group ready DTEs into envelopes and dispatch them. Returns envelope count.
     */
    public function pack(): int
    {
        $dtes = $this->fetchReadyDtes();

        if ($dtes->isEmpty()) {
            return 0;
        }

        $grouped = $this->groupByIssuerAndReceiver($dtes);
        $envelopesCreated = 0;
        $delayCounter = 0;
        $maxDocuments = $this->config->get('dte.envelopes.max_documents', 20);
        $maxHoldingMinutes = $this->config->get('dte.envelopes.max_holding_minutes', 30);

        foreach ($grouped as $group) {
            $oldest = $group->first();
            $holdingMinutes = $this->date->now()->diffInMinutes($oldest->updated_at);

            if ($group->count() >= $maxDocuments || $holdingMinutes >= $maxHoldingMinutes) {
                foreach ($group->chunk($maxDocuments) as $chunk) {
                    $envelope = $this->createEnvelope($chunk->all());
                    $this->dispatchEnvelope($envelope, $delayCounter++);
                    $envelopesCreated++;
                }
            }
        }

        return $envelopesCreated;
    }

    /**
     * Fetch the DTEs that are signed but without an envelope.
     *
     * @return EloquentCollection<int, SiiDte>
     */
    protected function fetchReadyDtes(): EloquentCollection
    {
        return SiiDte::query()
            ->with('payload')
            ->where('status', DteStatus::Signed)
            ->whereNull('sii_dte_envelope_id')
            ->oldest('updated_at')
            ->get([
                'id',
                'issuer_num',
                'issuer_vd',
                'status',
                'sii_dte_envelope_id',
                'document_type',
                'updated_at',
            ]);
    }

    /**
     * Returns a Collection of DTE grouped by issuer + receiver.
     *
     * @param  EloquentCollection<int, SiiDte>  $dtes
     * @return Collection<string, EloquentCollection<int, SiiDte>>
     */
    protected function groupByIssuerAndReceiver(EloquentCollection $dtes): Collection
    {
        return $dtes->groupBy(static function (SiiDte $dte): string {
            return $dte->issuer_rut->num.'-'.$dte->document_type->value;
        });
    }

    /**
     * Creates a DTE envelope on the database.
     *
     * @param  array<int, SiiDte>  $dtes
     */
    protected function createEnvelope(array $dtes): SiiDteEnvelope
    {
        $first = $dtes[array_key_first($dtes)]->load('payload:id,sii_dte_id,data');
        $isReceipt = in_array($first->document_type, [DteType::Receipt, DteType::ExemptReceipt], true);
        $issuerData = $first->payload->data['issuer'] ?? [];
        $dynamicIssuer = $this->configManager->getIssuer($first->issuer_rut);

        $envelope = SiiDteEnvelope::create([
            'issuer_rut' => $first->issuer_rut,
            'sender_rut' => $this->configManager->getSender($first->issuer_rut) ?? Rut::parse($this->config->get(
                'dte.sender.rut',
            )),
            'type' => $isReceipt ? 'boleta' : 'dte',
            'document_type' => $first->document_type,
            'resolution_date' => $issuerData['resolution_date'] ?? $dynamicIssuer?->resolutionDate ?? $this->config->get(
                'dte.issuer.resolution_date',
            ),
            'resolution_number' => $issuerData['resolution_number'] ?? $dynamicIssuer?->resolutionNumber ?? $this->config->get(
                'dte.issuer.resolution_number',
            ),
            'status' => EnvelopeStatus::Pending,
        ]);

        SiiDte::query()
            ->whereIn('id', array_map(static fn (SiiDte $dte) => $dte->getKey(), $dtes))
            ->update(['sii_dte_envelope_id' => $envelope->getKey()]);

        return $envelope;
    }

    /**
     * Dispatchs the job that compiles and sends the envelope.
     */
    protected function dispatchEnvelope(SiiDteEnvelope $envelope, int $delayCounter): void
    {
        $backoffSeconds = $this->config->get('dte.envelopes.backoff_seconds', 60);
        $delay = $delayCounter * $backoffSeconds;

        $this->artisan
            ->queue('dte:process-envelope', ['envelope_id' => $envelope->getKey()])
            ->onConnection($this->config->get('dte.queue.envelope.connection'))
            ->onQueue($this->config->get('dte.queue.envelope.name'))
            ->when($delay > 0, function (PendingDispatch $job) use ($delay) {
                return $job->delay($this->date->now()->addSeconds($delay));
            });
    }
}
