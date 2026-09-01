<?php

namespace Laragear\Dte\Builders\Concerns;

use Laragear\Dte\Data\ReferenceData;
use OverflowException;
use function count;

trait HasReferences
{
    protected const int MAX_REFERENCES = 40;

    /** @var list<ReferenceData> */
    protected array $references = [];

    /**
     * Add a document reference.
     */
    public function addReference(ReferenceData $reference): static
    {
        if (count($this->references) >= static::MAX_REFERENCES) {
            throw new OverflowException('A DTE cannot contain more than 40 references.');
        }

        $this->references[] = $reference;

        return $this;
    }

    /**
     * Return the document references.
     *
     * @return list<ReferenceData>
     */
    public function references(): array
    {
        return $this->references;
    }
}
