<?php

namespace Laragear\Dte\Testing\Fakes;

use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Builders\DebitNoteBuilder;
use Laragear\Dte\Events\DteCreated;
use Laragear\Dte\Events\DteCreating;
use Laragear\Dte\Models\SiiDte;
use LogicException;
use function value;

class FakeDebitNoteBuilder extends DebitNoteBuilder
{
    use FakeDocumentBuilder;

    /**
     * Skip storing the document to the database.
     */
    public static function withoutStoringDte(): static
    {
        static::$storeDte = false;

        return app(static::class);
    }

    public function create(mixed $sync = false): SiiDte
    {
        $this->validate();

        $this->events->dispatch(new DteCreating($this));

        $dte = $this->buildDte();

        if (value($sync, $dte, $this)) {
            $this->persistAndCompile($dte);
        }

        $this->events->dispatch(new DteCreated($dte));

        return $dte;
    }

    public function update(mixed $sync = false): SiiDte
    {
        if ($this->dte() === null) {
            throw new LogicException('Cannot update a document builder that has not been hydrated.');
        }

        $dte = $this->dte();
        $dte->forceFill($this->attributes())->saveQuietly();
        $dte->payload->forceFill(['data' => $this->payloadData()])->saveQuietly();

        if (value($sync, $dte, $this)) {
            Compile::forDte($dte);
        }

        static::$created[] = $dte;

        return $dte;
    }
}
