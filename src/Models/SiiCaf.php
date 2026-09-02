<?php

namespace Laragear\Dte\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laragear\Dte\Caf\Exceptions\FolioAlreadyAllocatedException;
use Laragear\Dte\Caf\Exceptions\FolioAlreadyAnnuledException;
use Laragear\Dte\Caf\Exceptions\FolioOutOfRangeException;
use Laragear\Dte\Caf\Folio;
use Laragear\Dte\Casts\AsFolio;
use Laragear\Dte\Database\Factories\SiiCafFactory;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Events\CafFoliosAnnuled;
use Laragear\Dte\Events\CafFoliosRestored;
use Laragear\Dte\Models\Concerns\HasDocumentType;
use Laragear\Rut\HasRut;
use Laragear\Rut\Rut;

/**
 * Stores an authorized SII folio range and its CAF XML.
 * ---
 * @see  SiiCafFactory
 * @link database/migrations/2026_01_01_000001_create_sii_cafs_table.php
 * ---
 * @mixin Builder<static>
 * ---
 * @method static SiiCafFactory factory(callable|array|int|null $count = null, callable|array $state = [])
 * @method Builder<static>|static newQuery()
 * @method Builder<static>|static query()
 * @method static Builder<static>|static collidesWith(Rut|string $rut, DteType|int $documentType, int $folioFrom, int $folioTo)
 * @method static static annulFolios(array<int|array{int, int}> $folios, string $reason = '', bool $validateAllocated = true)
 * @method static static restoreFolios(array<int|array{int, int}> $folios)
 * ---
 * @property-read int $id
 * ---
 * @property-read Rut $rut
 * ---
 * @property DteType $document_type
 * @property int $folio_from
 * @property int $folio_to
 * @property int $folio_current
 * @property array|null $folio_annuled
 * @property Carbon $authorized_on
 * @property Carbon|null $expires_on
 * @property string $xml
 * @property Folio $folios
 * ---
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 * ---
 * @method Builder<static>|static collidesWith(Rut|string $rut, DteType|int $documentType, int $folioFrom, int $folioTo)
 */
#[UseFactory(SiiCafFactory::class)]
#[Fillable(
    'rut',
    'document_type',
    'folio_from',
    'folio_to',
    'folio_current',
    'folio_annuled',
    'authorized_on',
    'expires_on',
    'xml',
)]
class SiiCaf extends Model
{
    /** @use HasFactory<SiiCafFactory> */
    use HasDocumentType;

    use HasFactory;
    use HasRut;

    public const string RUT_NUM = 'issuer_num';
    public const string RUT_VD = 'issuer_vd';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string|class-string>
     */
    protected $casts = [
        'document_type' => DteType::class,
        'authorized_on' => 'date',
        'expires_on' => 'date',
        'folios' => AsFolio::class,
        'folio_annuled' => 'array',
    ];

    /*
     |--------------------------------------------------------------------------
     | Scopes
     |--------------------------------------------------------------------------
     */

    /**
     * Scope the query to find CAFs that collide with the given properties.
     */
    public function scopeCollidesWith(
        Builder $query,
        Rut|string $rut,
        DteType|int $documentType,
        int $folioFrom,
        int $folioTo,
    ): Builder {
        return $query
            ->whereRut($rut)
            ->where('document_type', $documentType)
            ->where('folio_from', '<=', $folioTo)
            ->where('folio_to', '>=', $folioFrom);
    }

    /*
     |--------------------------------------------------------------------------
     | Relationships
     |--------------------------------------------------------------------------
     */

    /** @var EloquentCollection<int, SiiDte> */
    public EloquentCollection $dtes {
        get => $this->getRelationValue(__PROPERTY__);
    }

    /**
     * Return the documents allocated from this CAF.
     *
     * @return HasMany<SiiDte, static>
     */
    public function dtes(): HasMany
    {
        return $this->hasMany(SiiDte::class);
    }

    /*
     |--------------------------------------------------------------------------
     | Folio annulment
     |--------------------------------------------------------------------------
     */

    /**
     * Annul one or more folios, or ranges of folios, inside a locked transaction.
     *
     * @param  array<int|array{int, int}>  $folios
     */
    public function annulFolios(array $folios, string $reason = '', bool $validateAllocated = true): static
    {
        $folios = Folio::normalize($folios);

        return $this->getConnection()->transaction(function () use ($folios, $reason, $validateAllocated): static {
            $locked = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            foreach ($folios as $folio) {
                if ($locked->folios->isNotInRange($folio)) {
                    throw new FolioOutOfRangeException("The folio [$folio] is out of the CAF range.");
                }

                if ($validateAllocated && $locked->folios->isNotAllocatable($folio)) {
                    throw new FolioAlreadyAllocatedException("The folio [$folio] was already allocated.");
                }

                if ($locked->folios->isAnnuled($folio)) {
                    throw new FolioAlreadyAnnuledException("The folio [$folio] was already annulled.");
                }
            }

            $locked->folios->annul(...$folios);
            $locked->save();

            $this->getConnection()
                ->afterCommit(fn(): mixed => CafFoliosAnnuled::dispatch($locked, $folios));

            return $this->syncFolios($locked);
        });
    }

    /**
     * Restore one or more annulled folios locally, no SII report.
     *
     * @param  array<int|array{int, int}>  $folios
     */
    public function restoreFolios(array $folios): static
    {
        return $this->getConnection()->transaction(function () use ($folios): static {
            $locked = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            $locked->folios->restore(...$folios);
            $locked->save();

            $this->getConnection()
                ->afterCommit(fn(): mixed => CafFoliosRestored::dispatch($locked, $folios));

            return $this->syncFolios($locked);
        });
    }

    /**
     * Sync the in-memory folio state with the freshly locked and saved instance.
     */
    protected function syncFolios(SiiCaf $caf): static
    {
        $this->folios = $caf->folios;

        return $this;
    }
}
