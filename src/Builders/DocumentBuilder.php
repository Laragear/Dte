<?php

namespace Laragear\Dte\Builders;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Builders\Concerns\HasGlobalModifiers;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Contracts\Issuable;
use Laragear\Dte\Contracts\Receivable;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Data\Item;
use Laragear\Dte\Data\ReceiverData;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Events\DteCreated;
use Laragear\Dte\Events\DteCreating;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\SiiTaxes;
use Laragear\Rut\Rut;
use LogicException;
use function array_map;
use function array_merge;
use function min;
use function value;

abstract class DocumentBuilder
{
    use HasGlobalModifiers;

    /**
     * The issuer business.
     */
    protected ?IssuerData $issuer = null;

    /**
     * The receiver business or consumer.
     */
    protected ?ReceiverData $receiver = null;

    /**
     * The existing document being hydrated for retry.
     */
    protected ?SiiDte $dte = null;

    /**
     * The document detail lines.
     *
     * @var list<Item>
     */
    protected array $items = [];

    /**
     * The document references.
     *
     * @var list<ReferenceData>
     */
    protected array $references = [];

    /**
     * When the document was created.
     */
    protected DateTimeImmutable $issueDate;

    /**
     * Create a Document Builder instance.
     */
    public function __construct(
        protected ConsoleContract $artisan,
        protected Repository $config,
        protected Dispatcher $events,
        protected DateFactory $date,
    ) {
        $this->issueDate = $date->today('America/Santiago')->toDateTimeImmutable();
    }

    /**
     * Return the numeric SII document type.
     */
    abstract public function documentType(): DteType;

    /**
     * Add a detail line to the document.
     */
    abstract public function addItem(Item $item): static;

    /**
     * Return the document detail lines.
     *
     * @return list<Item>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Return all calculated document totals.
     *
     * @return array{net: int, exempt: int, tax: int, total: int}
     */
    abstract public function totals(): array;

    /**
     * Set the taxpayer issuing the document.
     */
    public function issuedBy(Issuable|IssuerData $issuer): static
    {
        if ($issuer instanceof Issuable) {
            $issuer = $issuer->toIssuer();
        }

        $this->issuer = $issuer;

        return $this;
    }

    /**
     * Set the taxpayer receiving the document.
     */
    public function receivedBy(Receivable|ReceiverData|Rut|string $receiver, ?string $name = null): static
    {
        $this->receiver = match (true) {
            $receiver instanceof Receivable => $receiver->toReceiver(),
            $receiver instanceof ReceiverData => $receiver,
            default => ReceiverData::make($receiver, $name),
        };

        return $this;
    }

    /**
     * Set the accounting issue date.
     */
    public function issuedOn(DateTimeImmutable $date): static
    {
        $this->issueDate = $date;

        return $this;
    }

    /**
     * Return the taxpayer issuing the document.
     */
    public function issuer(): IssuerData
    {
        return $this->issuer
            ?? app(ConfigurationManager::class)->getIssuer()
            ?? throw new LogicException('The DTE issuer has not been configured.');
    }

    /**
     * Return the document receiver when configured.
     */
    public function receiver(): ?ReceiverData
    {
        return $this->receiver;
    }

    /**
     * Return an empty reference list for documents without references.
     *
     * @return list<ReferenceData>
     */
    public function references(): array
    {
        return [];
    }

    /**
     * Restore the builder state from an existing document payload.
     *
     * @param  SiiDte  $dte
     */
    public function hydrate(SiiDte $dte): static
    {
        $this->dte = $dte;
        $dte->loadMissing('payload');

        $data = $dte->payload->data;

        if (isset($data['issued_on'])) {
            $this->issueDate = DateTimeImmutable::createFromFormat('Y-m-d', $data['issued_on']) ?: $this->issueDate;
        }

        if ($issuer = $data['issuer'] ?? null) {
            $this->issuedBy(IssuerData::make(
                $issuer['rut'],
                $issuer['legal_name'],
                $issuer['business_activity'],
                $issuer['economic_activity'],
                $issuer['address'],
                $issuer['commune'],
                $issuer['resolution_date'],
                $issuer['resolution_number'],
                $issuer['city'] ?? null,
                $issuer['telephone'] ?? null,
                $issuer['email'] ?? null,
                $issuer['branch'] ?? null,
            ));
        }

        if ($receiver = $data['receiver'] ?? null) {
            $this->receivedBy(ReceiverData::make(
                $receiver['rut'],
                $receiver['legal_name'],
                $receiver['business_activity'] ?? null,
                $receiver['email'] ?? null,
                $receiver['address'] ?? null,
                $receiver['commune'] ?? null,
                $receiver['city'] ?? null,
            ));
        }

        $this->items = array_map(
            fn (array $item): Item => Item::make(
                $item['name'],
                $item['unit_price'],
                $item['quantity'] ?? 1,
                $item['description'] ?? null,
                $item['unit'] ?? null,
                $item['code'] ?? null,
                $item['code_type'] ?? null,
                $item['discount_percentage'] ?? 0,
                $item['exempt'] ?? false,
                $item['taxes'] ?? [],
            ),
            $data['items'] ?? [],
        );

        $this->references = array_map(
            fn (array $reference): ReferenceData => ReferenceData::make(
                $reference['document_type'],
                $reference['folio'],
                new DateTimeImmutable($reference['date']),
                $reference['reason'] ?? null,
                $reference['reference_code'] ?? null,
            ),
            $data['references'] ?? [],
        );

        $this->globalModifiers = $data['global_modifiers'] ?? [];

        $this->hydrateAdditional($data);

        return $this;
    }

    /**
     * Restore subclass-specific input from the persisted payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function hydrateAdditional(array $data): void
    {
        //
    }

    /**
     * Persist the pending document and its raw input payload atomically.
     *
     * @param  (Closure(SiiDte $dte, static $builder): mixed)|mixed  $sync
     */
    public function create(mixed $sync = false): SiiDte
    {
        $this->validate();

        $this->events->dispatch(new DteCreating($this));

        // Persist this using a transaction. If ANYTHING fails, bail out.
        $dte = SiiDte::query()
            ->getConnection()
            ->transaction(function () use ($sync): SiiDte {
                $dte = $this->persist();

                // Here we will check if the user wants to compile the DTE immediately or send
                // it to a queue. When doing receipts, the developer should push the command
                // immediately so it becomes ready to print as a PDF file, otherwise wait.
                if (value($sync, $dte, $this)) {
                    $this->artisan->call('dte:compile', ['dte_id' => $dte]);
                } else {
                    $this->artisan
                        ->queue('dte:compile', ['dte_id' => $dte->getKey()])
                        ->onConnection($this->config->get('dte.queue.dte.connection'))
                        ->onQueue($this->config->get('dte.queue.dte.name'));
                }

                return $dte;
            });

        $this->events->dispatch(new DteCreated($dte));

        return $dte;
    }

    /**
     * Update the hydrated document and queue a fresh compilation.
     *
     * The folio and CAF are preserved so the retried document is resent with
     * the same folio, while prior error, status, and response state is reset.
     *
     * @param  (Closure(SiiDte $dte, static $builder): mixed)|mixed  $sync
     */
    public function update(mixed $sync = false): SiiDte
    {
        if ($this->dte === null) {
            throw new LogicException('Cannot update a document builder that has not been hydrated.');
        }

        $this->validate();

        return $this->dte->getConnection()->transaction(function () use ($sync): SiiDte {
            $dte = $this->dte;

            // Recalculate the aggregates and reset the previous processing state.
            $dte->forceFill(array_merge($this->attributes(), [
                'status' => DteStatus::Pending,
                'repairs' => null,
                'acknowledged_at' => null,
                'accepted_at' => null,
                'rejected_at' => null,
            ]))->save();

            // Replace the raw input payload and drop any compiled XML or SII response.
            $payload = $dte->payload()->updateOrCreate([], [
                'data' => $this->payloadData(),
                'xml' => null,
                'sii_response' => null,
            ]);

            $dte->setRelation('payload', $payload);

            if (value($sync, $dte, $this)) {
                $this->artisan->call('dte:compile', ['dte_id' => $dte]);
            } else {
                $this->artisan
                    ->queue('dte:compile', ['dte_id' => $dte->getKey()])
                    ->onConnection($this->config->get('dte.queue.dte.connection'))
                    ->onQueue($this->config->get('dte.queue.dte.name'));
            }

            return $dte;
        });
    }

    /**
     * Validate the common document input.
     */
    protected function validate(): void
    {
        $this->issuer();
        $this->receiverRut();

        if ($this->items() === []) {
            throw new LogicException('The DTE must contain at least one item.');
        }

        if (min($this->calculatedTotals()) < 0) {
            throw new LogicException('The DTE totals cannot be negative.');
        }

        $this->validateSpecific();
    }

    /**
     * Validate document-specific input.
     */
    protected function validateSpecific(): void
    {
        //
    }

    /**
     * Ensure the receiver contains mandatory B2B fields.
     *
     * B2B DTEs (e.g. invoices, debit/credit notes) strictly require
     * the receiver's business activity, address, and commune.
     */
    protected function validateB2bReceiver(): void
    {
        if (
            !$this->receiver
            || empty($this->receiver->businessActivity)
            || empty($this->receiver->address)
            || empty($this->receiver->commune)
        ) {
            throw new LogicException(
                'B2B documents require a receiver with a business activity, address, and commune.',
            );
        }
    }

    /**
     * Persist both records inside the active transaction.
     */
    protected function persist(): SiiDte
    {
        $dte = SiiDte::create($this->attributes());

        $payload = $dte->payload()->create(['data' => $this->payloadData()]);

        $dte->setRelation('payload', $payload);

        return $dte;
    }

    /**
     * Return initial document model attributes.
     *
     * @return array<string, mixed>
     */
    protected function attributes(): array
    {
        $totals = $this->calculatedTotals();

        return [
            'issuer_rut' => $this->issuer()->rut,
            'receiver_rut' => $this->receiverRut(),
            'document_type' => $this->documentType(),
            'issued_on' => $this->issueDate,
            'amount_net' => $totals['net'],
            'amount_exempt' => $totals['exempt'],
            'amount_taxes' => $totals['tax'],
            'taxes' => empty($taxes = $this->aggregateTaxes()) ? null : $taxes,
            'amount_total' => $totals['total'],
            'status' => DteStatus::Pending,
        ];
    }

    /**
     * Return the receiver RUT stored on the initial model.
     */
    protected function receiverRut(): Rut
    {
        return $this->receiver?->rut ?? throw new LogicException('The DTE receiver has not been configured.');
    }

    /**
     * Return document totals after applying document-specific rules.
     *
     * @return array{net: int, exempt: int, tax: int, total: int}
     */
    protected function calculatedTotals(): array
    {
        ['net' => $net, 'exempt' => $exempt, 'tax' => $tax] = $this->applyGlobalModifiers(
            $this->totals(),
        );

        $taxesEffect = $this->calculateRetentionsEffect();

        return [
            'net' => $net,
            'exempt' => $exempt,
            'tax' => $tax,
            'total' => $net + $exempt + $tax + $taxesEffect,
        ];
    }

    /**
     * Apply global modifiers (discounts/surcharges) and recalculate IVA.
     *
     * @param  array{net: int, exempt: int, tax: int, total: int}  $base
     * @return array{net: int, exempt: int, tax: int}
     */
    protected function applyGlobalModifiers(array $base): array
    {
        $net = $base['net'];
        $exempt = $base['exempt'];
        $tax = $base['tax'];
        $modified = false;

        foreach ($this->globalModifiers() as $modifier) {
            $modified = true;
            $isDiscount = $modifier['type'] === 'D';
            $sourceAmount = $modifier['target'] === 1 ? $exempt : $net;

            $modValue = $modifier['value_type'] === '%'
                ? (int) round($sourceAmount * ($modifier['value'] / 100), mode: PHP_ROUND_HALF_UP)
                : (int) round($modifier['value']);

            $effect = $isDiscount ? -$modValue : $modValue;

            if ($modifier['target'] === 1) {
                $exempt += $effect;
            } else {
                $net += $effect;
            }
        }

        if ($modified) {
            if ($this->documentType() === DteType::InvoiceExempt || $this->documentType() === DteType::ExemptReceipt) {
                $tax = 0;
            } else {
                $tax = (int) round($net * 0.19, mode: PHP_ROUND_HALF_UP);
            }
        }

        return ['net' => $net, 'exempt' => $exempt, 'tax' => $tax];
    }

    /**
     * Calculate the net effect of retentions (subtract) and additional taxes (add).
     */
    protected function calculateRetentionsEffect(): int
    {
        $taxesEffect = 0;

        foreach ($this->items() as $item) {
            foreach ($item->taxes as $code => $amount) {
                if (SiiTaxes::isRetention($code)) {
                    $taxesEffect -= $amount;
                } else {
                    $taxesEffect += $amount;
                }
            }
        }

        return $taxesEffect;
    }

    /**
     * Return the JSON-safe raw builder input.
     *
     * @return array<string, mixed>
     */
    protected function payloadData(): array
    {
        return array_merge([
            'document_type' => $this->documentType()->value,
            'issued_on' => $this->issueDate->format('Y-m-d'),
            'issuer' => $this->issuerData(),
            'receiver' => $this->receiverData(),
            'items' => array_map($this->itemData(...), $this->items()),
            'references' => array_map($this->referenceData(...), $this->references()),
            'global_modifiers' => $this->globalModifiers(),
            'taxes' => $this->aggregateTaxes(),
            'totals' => $this->calculatedTotals(),
        ], $this->additionalData());
    }

    /**
     * Aggregate item-level taxes into a keyed array.
     *
     * @return array<int, int> [ taxCode => totalAmount ]
     */
    protected function aggregateTaxes(): array
    {
        $taxes = [];

        foreach ($this->items() as $item) {
            foreach ($item->taxes as $taxCode => $amount) {
                $taxes[$taxCode] = ($taxes[$taxCode] ?? 0) + $amount;
            }
        }

        return $taxes;
    }

    /**
     * Return subclass-specific raw input.
     *
     * @return array<string, mixed>
     */
    protected function additionalData(): array
    {
        return [];
    }

    /**
     * Normalize the economic activity (Acteco) to an array of up to 4 tags.
     */
    protected function normalizeActeco(string|array $acteco): array
    {
        $tags = is_string($acteco) ? [$acteco] : array_values($acteco);

        if (count($tags) > 4) {
            throw new LogicException('The maximum number of Acteco tags allowed is 4.');
        }

        return $tags;
    }

    /**
     * Serialize issuer data for JSON storage.
     *
     * @return array<string, mixed>
     */
    protected function issuerData(): array
    {
        $issuer = $this->issuer();
        $global = app(ConfigurationManager::class)->getIssuer();

        return [
            'rut' => $issuer->rut->formatRaw(),
            'legal_name' => $issuer->legalName,
            'business_activity' => $issuer->businessActivity,
            'economic_activity' => $this->normalizeActeco($issuer->economicActivity),
            'address' => $issuer->address,
            'commune' => $issuer->commune,
            'city' => $issuer->city,
            'telephone' => $issuer->telephone ?? $global?->telephone ?? config('dte.issuer.telephone'),
            'email' => $issuer->email ?? $global?->email ?? config('dte.issuer.email'),
            'branch' => $issuer->branch ?? $global?->branch ?? config('dte.issuer.branch'),
            'resolution_date' => $issuer->resolutionDate ?? $global?->resolutionDate ?? config('dte.issuer.resolution_date'),
            'resolution_number' => $issuer->resolutionNumber ?? $global?->resolutionNumber ?? config('dte.issuer.resolution_number'),
        ];
    }

    /**
     * Serialize receiver data for JSON storage.
     *
     * @return array<string, mixed>|null
     */
    protected function receiverData(): ?array
    {
        $receiver = $this->receiver;

        return
            $receiver === null
                ? null
                : [
                'rut' => $receiver->rut->formatRaw(),
                'legal_name' => $receiver->legalName,
                'business_activity' => $receiver->businessActivity,
                'email' => $receiver->email,
                'address' => $receiver->address,
                'commune' => $receiver->commune,
                'city' => $receiver->city,
            ];
    }

    /**
     * Serialize item data for JSON storage.
     *
     * @return array<string, mixed>
     */
    protected function itemData(Item $item): array
    {
        return [
            'name' => $item->name,
            'unit_price' => $item->unitPrice,
            'quantity' => $item->quantity,
            'description' => $item->description,
            'unit' => $item->unit,
            'code' => $item->code,
            'code_type' => $item->codeType,
            'discount_percentage' => $item->discountPercentage,
            'exempt' => $item->exempt,
            'taxes' => $item->taxes,
        ];
    }

    /**
     * Serialize reference data for JSON storage.
     *
     * @return array<string, mixed>
     */
    protected function referenceData(ReferenceData $reference): array
    {
        return [
            'document_type' => $reference->documentType instanceof BackedEnum
                ? $reference->documentType->value
                : $reference->documentType,
            'folio' => $reference->folio,
            'date' => $reference->date->format('Y-m-d'),
            'reason' => $reference->reason,
            'reference_code' => $reference->referenceCode,
        ];
    }
}
