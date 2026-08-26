<?php

namespace Laragear\Dte\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Laragear\Dte\Enums\DteType;

/**
 * @method Builder<static>|static whereDocumentType(DteType $type)
 * @method Builder<static>|static invoices()
 * @method Builder<static>|static exemptInvoices()
 * @method Builder<static>|static receipts()
 * @method Builder<static>|static invoiceLiquidations()
 * @method Builder<static>|static purchaseInvoices()
 * @method Builder<static>|static dispatchGuides()
 * @method Builder<static>|static debitNotes()
 * @method Builder<static>|static creditNotes()
 */
trait HasDocumentType
{
    /**
     * Local scope to filter records by DTE type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeWhereDocumentType(Builder $query, DteType $type): Builder
    {
        return $query->where('document_type', $type->value);
    }

    /** @param  Builder<static>  $query */
    protected function scopeInvoices(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::Invoice);
    }

    /** @param  Builder<static>  $query */
    protected function scopeExemptInvoices(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::InvoiceExempt);
    }

    /** @param  Builder<static>  $query */
    protected function scopeReceipts(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::Receipt);
    }

    /** @param  Builder<static>  $query */
    protected function scopeInvoiceLiquidations(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::InvoiceLiquidation);
    }

    /** @param  Builder<static>  $query */
    protected function scopePurchaseInvoices(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::PurchaseInvoice);
    }

    /** @param  Builder<static>  $query */
    protected function scopeDispatchGuides(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::DispatchGuide);
    }

    /** @param  Builder<static>  $query */
    protected function scopeDebitNotes(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::DebitNote);
    }

    /** @param  Builder<static>  $query */
    protected function scopeCreditNotes(Builder $query): Builder
    {
        return $this->scopeWhereDocumentType($query, DteType::CreditNote);
    }
}
