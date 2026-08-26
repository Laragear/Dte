<?php

namespace Tests\Unit\Caf;

use Laragear\Dte\Caf\Folio;
use PHPUnit\Framework\TestCase;

class FolioTest extends TestCase
{
    public function test_annuls_individual_folios(): void
    {
        $folio = new Folio(1, 100, 1);
        $folio->annul(5, 7, '10');

        static::assertTrue($folio->isAnnuled(5));
        static::assertTrue($folio->isAnnuled(7));
        static::assertTrue($folio->isAnnuled(10));
        static::assertFalse($folio->isAnnuled(6));
    }

    public function test_annuls_ranges(): void
    {
        $folio = new Folio(1, 100, 1);
        $folio->annul('5-10');

        static::assertTrue($folio->isAnnuled(5));
        static::assertTrue($folio->isAnnuled(7));
        static::assertTrue($folio->isAnnuled(10));
        static::assertFalse($folio->isAnnuled(4));
        static::assertFalse($folio->isAnnuled(11));
    }

    public function test_mixed_annulments(): void
    {
        $folio = new Folio(1, 100, 1);
        $folio->annul(1, 2, '5-7', 9, '11-12');

        static::assertTrue($folio->isAnnuled(1));
        static::assertTrue($folio->isAnnuled(6));
        static::assertTrue($folio->isAnnuled(9));
        static::assertTrue($folio->isAnnuled(12));
        static::assertFalse($folio->isAnnuled(3));
        static::assertFalse($folio->isAnnuled(8));
        static::assertFalse($folio->isAnnuled(10));
    }

    public function test_restores_folios(): void
    {
        $folio = new Folio(1, 100, 1);
        $folio->annul(1, 2, '5-7');

        $folio->restore(1, '5-7');

        static::assertFalse($folio->isAnnuled(1));
        static::assertTrue($folio->isAnnuled(2));
        static::assertFalse($folio->isAnnuled(5));
    }

    public function test_remaining_folios(): void
    {
        $folio = new Folio(1, 10, 1);
        $folio->annul(2, '4-6');

        // available: 1, 3, 7, 8, 9, 10 (total 6)
        static::assertSame(6, $folio->remaining());
    }

    public function test_first_and_last_folios(): void
    {
        $folio = new Folio(1, 10, 1);
        $folio->annul(1, 2, 10);

        static::assertSame(3, $folio->first());
        static::assertSame(9, $folio->last());
    }

    public function test_blocks(): void
    {
        $folio = new Folio(1, 10, 1);
        $folio->annul(3, 4, 8);

        // available: 1, 2, 5, 6, 7, 9, 10
        $expected = [
            [1, 2],
            [5, 7],
            [9, 10],
        ];

        static::assertSame($expected, $folio->blocks());
    }

    public function test_next(): void
    {
        $folio = new Folio(1, 5, 1);
        $folio->annul(2, 4);

        static::assertSame(1, $folio->next());
        static::assertSame(3, $folio->next());
        static::assertSame(5, $folio->next());
        static::assertNull($folio->next());
    }

    public function test_annul_and_restore_range(): void
    {
        $folio = new Folio(1, 10, 5, []);
        $folio->annulRange(6, 8);
        static::assertSame(['6-8'], $folio->annuled);

        $folio->restoreRange(6, 8);
        static::assertSame([], $folio->annuled);
    }

    public function test_annul_returns_this(): void
    {
        $folio = new Folio(1, 10, 5, []);
        static::assertSame($folio, $folio->annul(6));
    }

    public function test_get_available_returns_null(): void
    {
        $folio = new Folio(1, 10, 10, ['1-10']);
        static::assertNull($folio->first());

        $folio = new Folio(1, 10, 11, []);
        static::assertNull($folio->first());
    }

    public function test_annul_all(): void
    {
        $folio = new Folio(1, 10, 5, []);
        $folio->annulAll();
        static::assertSame(['5-10'], $folio->annuled);
    }

    public function test_restore_string_number(): void
    {
        $folio = new Folio(1, 10, 5, [6, '7-8']);
        $folio->restore('6'); // Test the is_string && !str_contains
        static::assertSame(['7-8'], $folio->annuled);
    }

    public function test_next_returns_null_when_exhausted(): void
    {
        $folio = new Folio(1, 10, 10, []);
        static::assertSame(10, $folio->next());

        $folio->current = 11;
        static::assertNull($folio->next());
    }

    public function test_last_returns_null_when_all_remaining_annulled(): void
    {
        $folio = new Folio(1, 10, 8, ['8-10']);
        static::assertNull($folio->last());
    }
}
