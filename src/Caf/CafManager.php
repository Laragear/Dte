<?php

namespace Laragear\Dte\Caf;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laragear\Dte\Caf\Exceptions\CafNotFoundException;
use Laragear\Dte\Caf\Exceptions\DepletionException;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Events\CafDepleted;
use Laragear\Dte\Events\CafLoaded;
use Laragear\Dte\Models\SiiCaf;
use Laragear\Rut\Rut;
use RuntimeException;
use SplFileInfo;
use function implode;
use function today;

class CafManager
{
    /**
     * Create a Caf Manager instance.
     */
    public function __construct(
        protected Dispatcher $event,
        protected CafParser $parser,
        protected Filesystem $files,
    ) {
        //
    }

    /**
     * Parse and persist an SII CAF authorization.
     */
    public function store(string $xml): SiiCaf
    {
        $data = $this->parser->parse($xml);

        $caf = SiiCaf::create([
            'rut' => $data['issuer_rut'],
            ...Arr::only($data, [
                'document_type',
                'folio_from',
                'folio_to',
                'folio_current',
                'authorized_on',
                'xml',
            ]),
        ]);

        $this->event->dispatch(new CafLoaded($caf));

        return $caf;
    }

    /**
     * Parse and persist an SII CAF authorization from a file path or Uploaded File.
     */
    public function storeFile(string|SplFileInfo $file): SiiCaf
    {
        $path = $file instanceof SplFileInfo ? $file->getRealPath() : $file;

        try {
            $contents = $this->files->get($path);
        } catch (FileNotFoundException $e) {
            throw new RuntimeException("Unable to read the CAF file at [$path].", 0, $e);
        }

        return $this->store($contents);
    }

    /**
     * Allocate a folio and execute work inside the allocation transaction.
     *
     * @template TReturn
     *
     * @param  Closure(SiiCaf, int): TReturn  $callback
     * @return TReturn
     */
    public function allocate(Rut $issuer, DteType $documentType, Closure $callback): mixed
    {
        return SiiCaf::query()
            ->getConnection()
            ->transaction(function () use ($issuer, $documentType, $callback): mixed {
                while (true) {
                    $caf = $this->availableCaf($issuer, $documentType);

                    $folio = $caf->folios->next();

                    $caf->save();

                    // If a valid folio was successfully pulled, execute the callback.
                    // Otherwise, the loop continues to look for the next valid CAF.
                    if ($folio !== null) {
                        return $callback($caf, $folio);
                    }
                }
            });
    }

    /**
     * Find the CAF covering the given folios and annul them.
     */
    public function annulFolios(Rut|string $issuer, DteType|int $documentType, string $reason, array $folios): SiiCaf
    {
        $issuer = Rut::parse($issuer);
        $documentType = $documentType instanceof DteType ? $documentType : DteType::from($documentType);
        $folios = Folio::normalize($folios);

        if ($folios === []) {
            throw new InvalidArgumentException('No folios given to annul.');
        }

        $caf = SiiCaf::query()
            ->whereRut($issuer)
            ->whereDocumentType($documentType)
            ->where('folio_from', '<=', min($folios))
            ->where('folio_to', '>=', max($folios))
            ->firstOr(static function () use ($issuer, $documentType, $folios): never {
                throw new CafNotFoundException(
                    "No CAF covers the issuer [$issuer] and document type [$documentType->value] for the folios [".
                    implode(', ', $folios)
                    .'].',
                );
            });

        return $caf->annulFolios($folios, $reason);
    }

    /**
     * Find and lock the next CAF with an available folio.
     *
     * @transaction static::allocate()
     */
    protected function availableCaf(Rut $issuer, DteType $documentType): SiiCaf
    {
        return SiiCaf::query()
            ->whereRut($issuer)
            ->whereDocumentType($documentType)
            ->whereColumn('folio_current', '<=', 'folio_to')
            ->where(static function (Builder $query): void {
                $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', today('America/Santiago'));
            })
            ->orderBy('folio_from')
            ->lockForUpdate()
            ->firstOr([
                'id',
                'rut_num',
                'rut_vd',
                'document_type',
                'folio_from',
                'folio_to',
                'folio_current',
                'folio_annuled',
                'authorized_on',
            ], function () use ($issuer, $documentType): never {
                $this->event->dispatch(new CafDepleted($issuer, $documentType));

                throw new DepletionException(
                    "No CAF folios available for the issuer [$issuer] and document type [$documentType->value].",
                );
            });
    }
}
