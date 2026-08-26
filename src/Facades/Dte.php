<?php

namespace Laragear\Dte\Facades;

use Illuminate\Support\Facades\Facade;
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
use Laragear\Dte\Models\SiiDte;

/**
 * @method static InvoiceBuilder invoice()
 * @method static ReceiptBuilder receipt()
 * @method static CreditNoteBuilder creditNote()
 * @method static DebitNoteBuilder debitNote()
 * @method static DispatchGuideBuilder dispatchGuide()
 * @method static PurchaseInvoiceBuilder purchaseInvoice()
 * @method static InvoiceLiquidationBuilder invoiceLiquidation()
 * @method static AecBuilder aec()
 * @method static AecCessionBuilder cede(SiiDte $dte)
 *
 * @see Builder
 */
class Dte extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Builder::class;
    }
}
