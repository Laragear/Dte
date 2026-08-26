<?php

namespace Laragear\Dte\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Laragear\Dte\Builders\AecCessionBuilder;
use Laragear\Dte\Database\Factories\SiiDteFactory;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\Concerns\HasDocumentType;
use Laragear\Dte\Models\Concerns\HasSiiStatus;
use Laragear\Dte\Pdf\PdfBuilder;
use Laragear\Rut\Eloquent\RutAttribute;
use Laragear\Rut\Rut;
use function app;

/**
 * Stores the queryable header and lifecycle of an emitted DTE.
 * ---
 * @see  SiiDteFactory
 * @link database/migrations/2026_01_01_000002_create_sii_dtes_table.php
 * ---
 * @mixin Builder<static>
 * ---
 * @method static SiiDteFactory<static> factory(callable|array|int|null $count = null, callable|array $state = [])
 * @method Builder<static>|static newQuery()
 * @method static Builder<static>|static query()
 * ---
 * @property-read int $id
 * ---
 * @property Rut $issuer_rut
 * @property Rut $receiver_rut
 * @property DteType $document_type
 * @property int|null $folio
 * @property Carbon|null $issued_on
 * @property int $amount_net
 * @property int $amount_exempt
 * @property int $amount_taxes
 * @property int $amount_total
 * @property DteStatus $status
 * @property array<string, int>|null $taxes
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * ---
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 * ---
 * @method Builder<static>|Builder whereHasRepairs()
 * @method Builder<static>|Builder whereDoesntHaveRepairs()
 */
#[UseFactory(SiiDteFactory::class)]
#[Fillable(
    'issuer_rut',
    'receiver_rut',
    'document_type',
    'folio',
    'issued_on',
    'amount_net',
    'amount_exempt',
    'amount_taxes',
    'taxes',
    'amount_total',
    'status',
    'repairs',
    'repairs',
)]
class SiiDte extends Model
{
    /** @use HasFactory<SiiDteFactory> */
    use HasFactory;
    use HasSiiStatus;
    use HasDocumentType;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'pack_retries' => 'integer',
        'document_type' => DteType::class,
        'issued_on' => 'date',
        'status' => DteStatus::class,
        'repairs' => 'array',
        'taxes' => 'array',
        'acknowledged_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /*
     |--------------------------------------------------------------------------
     | Relationships
     |--------------------------------------------------------------------------
     */

    public ?SiiCaf $caf {
        get => $this->getRelationValue(__PROPERTY__);
    }

    /**
     * Return the CAF that allocated this document folio.
     *
     * @return BelongsTo<SiiCaf, static>
     */
    public function caf(): BelongsTo
    {
        return $this->belongsTo(SiiCaf::class, 'sii_caf_id');
    }

    public ?SiiDteEnvelope $envelope {
        get => $this->getRelationValue(__PROPERTY__);
    }

    /**
     * Return the envelope containing this document.
     *
     * @return BelongsTo<SiiDteEnvelope, static>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SiiDteEnvelope::class, 'sii_dte_envelope_id');
    }

    public ?SiiDtePayload $payload {
        get => $this->getRelationValue(__PROPERTY__);
    }

    /**
     * Return the input and XML payload for this document.
     *
     * @return HasOne<SiiDtePayload, static>
     */
    public function payload(): HasOne
    {
        return $this->hasOne(SiiDtePayload::class);
    }

    /** @var EloquentCollection<int, SiiAecCession> */
    public EloquentCollection $aecCessions {
        get => $this->getRelationValue(__PROPERTY__);
    }

    /**
     * Return the credit cessions registered for this document.
     *
     * @return HasMany<SiiAecCession, static>
     */
    public function aecCessions(): HasMany
    {
        return $this->hasMany(SiiAecCession::class);
    }

    /*
     |--------------------------------------------------------------------------
     | Local scopes
     |--------------------------------------------------------------------------
     */

    /**
     * Scope a query to only include models that have SII repairs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereHasRepairs(Builder $query): Builder
    {
        return $query->whereNotNull('repairs');
    }

    /**
     * Scope a query to only include models that do not have SII repairs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereDoesntHaveRepairs(Builder $query): Builder
    {
        return $query->whereNull('repairs');
    }

    /*
     |--------------------------------------------------------------------------
     | Attributes
     |--------------------------------------------------------------------------
     */

    /**
     * Access/Mutate the "issuer_rut" attribute.
     */
    protected function issuerRut(): Attribute
    {
        return RutAttribute::for('issuer');
    }

    /**
     * Access/Mutate the "receiver_rut" attribute.
     */
    protected function receiverRut(): Attribute
    {
        return RutAttribute::for('receiver');
    }

    /*
     |--------------------------------------------------------------------------
     | Helpers
     |--------------------------------------------------------------------------
     */

    /**
     * Check if the document was accepted but contains SII repairs/rejections.
     */
    public function isAcceptedWithRepairs(): bool
    {
        return $this->status === DteStatus::Accepted && !empty($this->repairs);
    }

    /**
     * Check if the document was accepted without any repairs/rejections.
     */
    public function isNotAcceptedWithRepairs(): bool
    {
        return !$this->isAcceptedWithRepairs();
    }

    /*
     |--------------------------------------------------------------------------
     | AEC Cession
     |--------------------------------------------------------------------------
     */

    /**
     * Create a fluent builder to cede this document.
     */
    public function cede(): AecCessionBuilder
    {
        return app(AecCessionBuilder::class)->forDte($this);
    }

    /*
     |--------------------------------------------------------------------------
     | PDF Generation
     |--------------------------------------------------------------------------
     */

    /**
     * Create a fluent builder to generate or manage the PDF for this document.
     */
    public function pdf(): PdfBuilder
    {
        return app(PdfBuilder::class)->forDte($this);
    }

    /*
     |--------------------------------------------------------------------------
     | Replication for retry
     |--------------------------------------------------------------------------
     */

    /**
     * Replicates this document for retry, clearing its folio, status, and envelope.
     *
     * Use this helper to safely retry a DTE that was rejected due to business data errors.
     */
    public function replicateForRetry(array $except = []): static
    {
        $original = $this;

        return tap($this->replicate(array_merge($except, [
            'sii_caf_id',
            'sii_dte_envelope_id',
            'folio',
            'status',
            'repairs',
            'repairs',
            'pack_retries',
            'acknowledged_at',
            'accepted_at',
            'rejected_at',
        ])), static function (self $clone) use ($original) {
            $clone->status = DteStatus::Pending;
            $clone->save();

            // Replicate payload as well
            if ($original->relationLoaded('payload') || $original->payload) {
                $payloadClone = $original->payload->replicate(['sii_dte_id']);
                $clone->payload()->save($payloadClone);
            }
        });
    }
}
