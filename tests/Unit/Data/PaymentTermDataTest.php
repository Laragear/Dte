<?php

namespace Tests\Unit\Data;

use DateTimeImmutable;
use Laragear\Dte\Data\PaymentTermData;
use Tests\TestCase;

class PaymentTermDataTest extends TestCase
{
    public function test_make_creates_payment_term_data(): void
    {
        $date = new DateTimeImmutable('2026-08-13');
        $term = PaymentTermData::make('Credit', $date);

        static::assertSame('Credit', $term->condition);
        static::assertSame($date, $term->expirationDate);
    }
}
