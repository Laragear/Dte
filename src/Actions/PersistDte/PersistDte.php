<?php

namespace Laragear\Dte\Actions\PersistDte;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Builders\DocumentBuilder;
use Laragear\Dte\Models\SiiDte;

/**
 * @method SiiDte thenReturn()
 */
class PersistDte extends Pipeline
{
    /**
     * @var list<class-string>
     */
    protected $pipes = [
        Pipes\ValidateDocument::class,
        Pipes\PersistDocument::class,
        Pipes\QueueCompilation::class,
    ];

    /**
     * Stores the DTE into the database, returning its model.
     */
    public function handle(DocumentBuilder $builder, mixed $sync = false, bool $isUpdate = false): SiiDte
    {
        $data = new DteData(
            builder: $builder,
            attributes: $builder->attributes(),
            payloadData: $builder->payloadData(),
            sync: $sync,
            isUpdate: $isUpdate,
        );

        return $this->send($data)->thenReturn()->dte;
    }
}
