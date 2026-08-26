<?php

namespace Laragear\Dte\Caf;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Laragear\Dte\Caf\Exceptions\DepletionException;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Events\CafDepleted;
use Laragear\Dte\Events\CafLoaded;
use Laragear\Dte\Models\SiiCaf;
use Laragear\Rut\Rut;
use RuntimeException;
use SplFileInfo;

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
                $caf = $this->availableCaf($issuer, $documentType);

                $folio = $caf->folios->next();

                if ($folio === null) {
                    $caf->save();

                    return $this->allocate($issuer, $documentType, $callback);
                }

                $caf->save();

                return $callback($caf, $folio);
            });
    }

    /**
     * Find and lock the next CAF with an available folio.
     */
    protected function availableCaf(Rut $issuer, DteType $documentType): SiiCaf
    {
        $caf = SiiCaf::query()
            ->whereRut($issuer)
            ->whereDocumentType($documentType)
            ->whereColumn('folio_current', '<=', 'folio_to')
            ->where(static function (Builder $query): void {
                $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', today('America/Santiago'));
            })
            ->orderBy('folio_from')
            ->lockForUpdate()
            ->first([
                'id',
                'rut_num',
                'rut_vd',
                'document_type',
                'folio_from',
                'folio_to',
                'folio_current',
                'folio_annuled',
                'authorized_on',
            ]);

        if ($caf === null) {
            $this->event->dispatch(new CafDepleted($issuer, $documentType));

            throw new DepletionException(
                'No valid CAF has available folios for this issuer and document type.',
            );
        }

        return $caf;
    }
}
