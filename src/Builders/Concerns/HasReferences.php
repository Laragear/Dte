<?php

namespace Laragear\Dte\Builders\Concerns;

use InvalidArgumentException;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\ReferenceType;
use OverflowException;

use function count;
use function preg_match;
use function sprintf;

trait HasReferences
{
    protected const int MAX_REFERENCES = 40;

    /** @var list<ReferenceData> */
    protected array $references = [];

    protected ?string $testSetCase = null;

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
     * Mark this document as part of a certification test set case.
     *
     * The SET reference is prepended as the first reference line on read,
     * which survives correction methods that replace the references array.
     */
    public function forTestCase(string $case): static
    {
        if (! preg_match('/^\d+-\d+$/', $case)) {
            throw new InvalidArgumentException(
                sprintf('Invalid test case format [%s]. Expected "NNNNN-N" (e.g. "5034081-1").', $case),
            );
        }

        $this->testSetCase = $case;

        return $this;
    }

    /**
     * Return the document references.
     *
     * When a test set case is active, the SET reference is prepended on read.
     */
    public function references(): array
    {
        $references = $this->references;

        if ($this->testSetCase !== null && ! empty($references) && $references[0]->documentType === ReferenceType::TestSet) {
            return $references;
        }

        if ($this->testSetCase !== null && ($references === [] || $references[0]->documentType !== ReferenceType::TestSet)) {
            array_unshift(
                $references,
                ReferenceData::make(
                    ReferenceType::TestSet,
                    '0',
                    $this->issueDate,
                    "CASO {$this->testSetCase}",
                ),
            );
        }

        return $references;
    }
}
