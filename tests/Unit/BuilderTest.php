<?php

namespace Tests\Unit;

use Generator;
use Laragear\Dte\Builder;
use Laragear\Dte\Builders\AecBuilder;
use Laragear\Dte\Builders\AecCessionBuilder;
use Laragear\Dte\Builders\CreditNoteBuilder;
use Laragear\Dte\Builders\DebitNoteBuilder;
use Laragear\Dte\Builders\DispatchGuideBuilder;
use Laragear\Dte\Builders\InvoiceBuilder;
use Laragear\Dte\Builders\InvoiceLiquidationBuilder;
use Laragear\Dte\Builders\PurchaseInvoiceBuilder;
use Laragear\Dte\Builders\ReceiptBuilder;
use Laragear\Dte\Facades\Dte;
use Laragear\Dte\Models\SiiDte;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuilderTest extends TestCase
{
    /** @return Generator<string, array{string, class-string}> */
    public static function builders(): Generator
    {
        yield 'invoice' => ['invoice', InvoiceBuilder::class];
        yield 'receipt' => ['receipt', ReceiptBuilder::class];
        yield 'credit note' => ['creditNote', CreditNoteBuilder::class];
        yield 'debit note' => ['debitNote', DebitNoteBuilder::class];
        yield 'dispatch guide' => ['dispatchGuide', DispatchGuideBuilder::class];
        yield 'purchase invoice' => ['purchaseInvoice', PurchaseInvoiceBuilder::class];
        yield 'invoice liquidation' => ['invoiceLiquidation', InvoiceLiquidationBuilder::class];
    }

    /** @param  class-string  $expected */
    #[DataProvider('builders')]
    public function test_builds_documents_through_the_service_container(string $method, string $expected): void
    {
        $instance = $this->mock($expected);

        static::assertSame($instance, $this->app->make(Builder::class)->{$method}());
    }

    /** @param  class-string  $expected */
    #[DataProvider('builders')]
    public function test_dte_facade_is_the_document_builder_entry_point(string $method, string $expected): void
    {
        static::assertInstanceOf($expected, Dte::{$method}());
    }

    public function test_builds_aec(): void
    {
        $instance = $this->mock(AecBuilder::class);
        static::assertSame($instance, $this->app->make(Builder::class)->aec());
    }

    public function test_builds_aec_cession(): void
    {
        $dte = new SiiDte;
        $instance = $this->mock(AecCessionBuilder::class);
        $instance->expects('forDte')->with($dte)->andReturn($instance);

        static::assertSame($instance, $this->app->make(Builder::class)->cede($dte));
    }
}
