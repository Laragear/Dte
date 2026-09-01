<?php

namespace Laragear\Dte\Caf;

class Folio
{
    /**
     * Create a new Folio instance.
     */
    public function __construct(
        public int $from,
        public int $to,
        public int $current,
        public array $annuled = [],
    ) {
        //
    }

    /**
     * Annuls specific folios or ranges (e.g., 4, 5, '7-88').
     *
     * @return $this
     */
    public function annul(int|string ...$folios): static
    {
        foreach ($folios as $folio) {
            // Normalize to string ranges or integers
            if (is_string($folio) && !str_contains($folio, '-')) {
                $folio = (int) $folio;
            }

            if (!in_array($folio, $this->annuled, true)) {
                $this->annuled[] = $folio;
            }
        }

        return $this;
    }

    /**
     * Annuls a range of folios explicitly.
     *
     * @return $this
     */
    public function annulRange(int $from, int $to): static
    {
        return $this->annul("$from-$to");
    }

    /**
     * Annuls all remaining folios.
     *
     * @return $this
     */
    public function annulAll(): static
    {
        return $this->annulRange($this->current, $this->to);
    }

    /**
     * Restores specific folios or ranges from annulment.
     *
     * @return $this
     */
    public function restore(int|string ...$folios): static
    {
        foreach ($folios as $folio) {
            if (is_string($folio) && !str_contains($folio, '-')) {
                $folio = (int) $folio;
            }

            $key = array_search($folio, $this->annuled, true);

            if ($key !== false) {
                unset($this->annuled[$key]);
            }
        }

        $this->annuled = array_values($this->annuled);

        return $this;
    }

    /**
     * Restores a range of folios from annulment.
     *
     * @return $this
     */
    public function restoreRange(int $from, int $to): static
    {
        return $this->restore("$from-$to");
    }

    /**
     * Checks if a folio is annulled.
     */
    public function isAnnuled(int $folio): bool
    {
        foreach ($this->annuled as $item) {
            if (is_int($item) && $item === $folio) {
                return true;
            }
            if (is_string($item) && str_contains($item, '-')) {
                [$from, $to] = explode('-', $item, 2);
                if ($folio >= (int) $from && $folio <= (int) $to) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if a folio is not annulled.
     */
    public function isNotAnnuled(int $folio): bool
    {
        return !$this->isAnnuled($folio);
    }

    /**
     * Returns the first available folio (accounting for annulled).
     */
    public function first(): ?int
    {
        $folio = $this->current;

        while ($this->isAnnuled($folio) && $folio <= $this->to) {
            $folio++;
        }

        if ($folio > $this->to) {
            return null;
        }

        return $folio;
    }

    /**
     * Returns the last available folio in the CAF (accounting for annulled).
     */
    public function last(): ?int
    {
        $folio = $this->to;

        while ($this->isAnnuled($folio) && $folio >= $this->current) {
            $folio--;
        }

        if ($folio < $this->current) {
            return null;
        }

        return $folio;
    }

    /**
     * Returns the number of available folios remaining.
     */
    public function remaining(): int
    {
        $count = 0;

        for ($i = $this->current; $i <= $this->to; $i++) {
            if ($this->isNotAnnuled($i)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Groups consecutive available folio ranges into groups, like `[[1, 4], [6, 10]]`.
     */
    public function blocks(): array
    {
        $blocks = [];
        $blockStart = null;

        for ($i = $this->current; $i <= $this->to; $i++) {
            if ($this->isNotAnnuled($i)) {
                if ($blockStart === null) {
                    $blockStart = $i;
                }
            } else {
                if ($blockStart !== null) {
                    $blocks[] = [$blockStart, $i - 1];
                    $blockStart = null;
                }
            }
        }

        if ($blockStart !== null) {
            $blocks[] = [$blockStart, $this->to];
        }

        return $blocks;
    }

    /**
     * Returns the next available folio and increments the current pointer, or null if the CAF is depleted.
     */
    public function next(): ?int
    {
        while ($this->isAnnuled($this->current) && $this->current <= $this->to) {
            $this->current++;
        }

        if ($this->current > $this->to) {
            return null;
        }

        $allocated = $this->current;

        $this->current++;

        return $allocated;
    }
}
