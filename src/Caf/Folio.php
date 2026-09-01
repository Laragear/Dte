<?php

namespace Laragear\Dte\Caf;

use InvalidArgumentException;

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
     * Normalizes folio arguments (integers and `[from, to]` ranges) into a flat list of concrete folio numbers.
     *
     * @param array<int|string|array{int, int}> $folios
     *
     * @return array<int>
     */
    public static function normalize(array $folios): array
    {
        $normalized = [];

        foreach ($folios as $folio) {
            if (is_array($folio)) {
                [$from, $to] = self::range($folio);

                for ($folio = $from; $folio <= $to; $folio++) {
                    $normalized[] = $folio;
                }

                continue;
            }

            $normalized[] = (int) $folio;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalizes a `[from, to]` pair into an ascending range, reversing flipped pairs.
     *
     * @return array{int, int}
     */
    private static function range(array $range): array
    {
        if (count($range) !== 2 || !is_numeric($range[0]) || !is_numeric($range[1])) {
            throw new InvalidArgumentException('Folio ranges must be a pair of numbers, like [from, to].');
        }

        $from = (int) $range[0];
        $to = (int) $range[1];

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /**
     * Annuls specific folios or ranges (e.g., 4, 5, [7, 88]).
     *
     * @return $this
     */
    public function annul(int|string|array ...$folios): static
    {
        foreach ($folios as $folio) {
            if (is_array($folio)) {
                $folio = self::range($folio);
            } elseif (is_string($folio)) {
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
        return $this->annul([$from, $to]);
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
    public function restore(int|string|array ...$folios): static
    {
        foreach ($folios as $folio) {
            if (is_array($folio)) {
                $folio = self::range($folio);
            } elseif (is_string($folio)) {
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
        return $this->restore([$from, $to]);
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
            if (is_array($item)) {
                [$from, $to] = $item;
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                if ($folio >= $from && $folio <= $to) {
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
     * Checks if a folio is inside the CAF range.
     */
    public function isInRange(int $folio): bool
    {
        return $this->from <= $folio && $folio <= $this->to;
    }

    /**
     * Check if a folio is not inside the CAF range.
     */
    public function isNotInRange(int $folio): bool
    {
        return !$this->isInRange($folio);
    }

    /**
     * Checks if a folio is allocatable (inside the range and not yet handed out).
     */
    public function isAllocatable(int $folio): bool
    {
        return $folio >= $this->current && $this->isInRange($folio);
    }

    /**
     * Check if a folio is not allocatable (outside the range or already handed out).
     */
    public function isNotAllocatable(int $folio): bool
    {
        return !$this->isAllocatable($folio);
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
