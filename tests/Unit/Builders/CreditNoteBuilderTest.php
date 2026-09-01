<?php

namespace Tests\Unit\Builders;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Laragear\Dte\Builders\CreditNoteBuilder;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;
use Laragear\Dte\Models\SiiDte;
use Tests\DatabaseTestCase;

class CreditNoteBuilderTest extends DatabaseTestCase
{
    public function test_annul_overwrites_references(): void
    {
        $builder = $this->app->make(CreditNoteBuilder::class);
        $date = new DateTimeImmutable('2026-08-15');

        $builder->annul(DteType::Invoice, '123', $date);

        $references = $builder->references();
        static::assertCount(1, $references);
        static::assertSame(DteType::Invoice, $references[0]->documentType);
        static::assertSame('123', $references[0]->folio);
        static::assertSame($date, $references[0]->date);
        static::assertSame('Anula documento', $references[0]->reason);
        static::assertSame(1, $references[0]->referenceCode);
    }

    public function test_amend_overwrites_references(): void
    {
        $builder = $this->app->make(CreditNoteBuilder::class);
        $date = new DateTimeImmutable('2026-08-15');

        $builder->amend(DteType::InvoiceExempt, '456', $date);

        $references = $builder->references();
        static::assertCount(1, $references);
        static::assertSame(DteType::InvoiceExempt, $references[0]->documentType);
        static::assertSame('456', $references[0]->folio);
        static::assertSame($date, $references[0]->date);
        static::assertSame('Corrige texto', $references[0]->reason);
        static::assertSame(2, $references[0]->referenceCode);
    }

    public function test_discount_overwrites_references(): void
    {
        $builder = $this->app->make(CreditNoteBuilder::class);
        $date = new DateTimeImmutable('2026-08-15');

        $builder->discount(ReferenceType::PurchaseOrder, 'PO-789', $date);

        $references = $builder->references();
        static::assertCount(1, $references);
        static::assertSame(ReferenceType::PurchaseOrder, $references[0]->documentType);
        static::assertSame('PO-789', $references[0]->folio);
        static::assertSame($date, $references[0]->date);
        static::assertSame('Corrige montos', $references[0]->reason);
        static::assertSame(3, $references[0]->referenceCode);
    }

    public function test_methods_accept_sii_dte(): void
    {
        $dte = SiiDte::factory()->make([
            'document_type' => DteType::Invoice,
            'folio' => 123,
            'issued_on' => new Carbon('2026-08-15'),
        ]);

        $builder = $this->app->make(CreditNoteBuilder::class);

        $builder->annul($dte);
        static::assertSame('123', $builder->references()[0]->folio);

        $builder->amend($dte);
        static::assertSame('123', $builder->references()[0]->folio);

        $builder->discount($dte);
        static::assertSame('123', $builder->references()[0]->folio);
    }
}
