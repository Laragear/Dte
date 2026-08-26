<?php

namespace Laragear\Dte;

use Illuminate\Contracts\Container\Container;
use Laragear\Dte\Builders\AecBuilder;
use Laragear\Dte\Builders\AecCessionBuilder;
use Laragear\Dte\Builders\CreditNoteBuilder;
use Laragear\Dte\Builders\DebitNoteBuilder;
use Laragear\Dte\Builders\DispatchGuideBuilder;
use Laragear\Dte\Builders\InvoiceBuilder;
use Laragear\Dte\Builders\InvoiceLiquidationBuilder;
use Laragear\Dte\Builders\PurchaseInvoiceBuilder;
use Laragear\Dte\Builders\ReceiptBuilder;
use Laragear\Dte\Models\SiiDte;

class Builder
{
    /**
     * Create a new Builder instance.
     */
    public function __construct(
        protected Container $app,
    ) {
        //
    }

    /**
     * Create a invoice fluent builder.
     */
    public function invoice(): InvoiceBuilder
    {
        return $this->app->make(InvoiceBuilder::class);
    }

    /**
     * Create a receipt fluent builder.
     */
    public function receipt(): ReceiptBuilder
    {
        return $this->app->make(ReceiptBuilder::class);
    }

    /**
     * Create a credit note fluent builder.
     */
    public function creditNote(): CreditNoteBuilder
    {
        return $this->app->make(CreditNoteBuilder::class);
    }

    /**
     * Create a debit note fluent builder.
     */
    public function debitNote(): DebitNoteBuilder
    {
        return $this->app->make(DebitNoteBuilder::class);
    }

    /**
     * Create a dispatch guide fluent builder.
     */
    public function dispatchGuide(): DispatchGuideBuilder
    {
        return $this->app->make(DispatchGuideBuilder::class);
    }

    /**
     * Create a purchase invoice fluent builder.
     */
    public function purchaseInvoice(): PurchaseInvoiceBuilder
    {
        return $this->app->make(PurchaseInvoiceBuilder::class);
    }

    /**
     * Create a invoice liquidation fluent builder.
     */
    public function invoiceLiquidation(): InvoiceLiquidationBuilder
    {
        return $this->app->make(InvoiceLiquidationBuilder::class);
    }

    /**
     * Create an AEC builder.
     */
    public function aec(): AecBuilder
    {
        return $this->app->make(AecBuilder::class);
    }

    /**
     * Create an AEC Cession builder for a specific document.
     */
    public function cede(SiiDte $dte): AecCessionBuilder
    {
        return $this->app->make(AecCessionBuilder::class)->forDte($dte);
    }
}
