<?php

namespace Laragear\Dte\Enums;

use Closure;
use Illuminate\Support\Collection;

enum DteType: int
{
    use Concerns\EnumHelpers;

    public const self DEFAULT = self::Invoice;

    /** Paper invoice (Factura) */
    case InvoicePhysical = 30;

    /** Exempt paper invoice (Factura de Ventas y Servicios no Afectos o exentos IVA) */
    case InvoicePhysicalExempt = 32;

    /** Electronic invoice (Factura Electrónica) */
    case Invoice = 33;

    /** Exempt electronic invoice (Factura Electrónica de Ventas y Servicios no afectos o exentos IVA) */
    case InvoiceExempt = 34;

    /** Electronic receipt. */
    case Receipt = 39;

    /** Exempt electronic receipt. */
    case ExemptReceipt = 41;

    /** Electronic invoice liquidation. */
    case InvoiceLiquidation = 43;

    /** Electronic purchase invoice. */
    case PurchaseInvoice = 46;

    /** Electronic dispatch guide. */
    case DispatchGuide = 52;

    /** Electronic debit note. */
    case DebitNote = 56;

    /** Electronic credit note. */
    case CreditNote = 61;

    /**
     * Check if the DTE Type is essentially a receipt.
     */
    public function isReceipt(): bool
    {
        return $this === self::Receipt || $this === self::ExemptReceipt;
    }

    /**
     * Returns the label.
     */
    public function label(): string
    {
        return match ($this) {
            self::InvoicePhysical => 'Factura',
            self::InvoicePhysicalExempt => 'Factura Exenta',
            self::Invoice => 'Factura Electrónica',
            self::InvoiceExempt => 'Factura Electrónica Exenta',
            self::Receipt => 'Boleta',
            self::ExemptReceipt => 'Boleta Exenta',
            self::InvoiceLiquidation => 'Liquidación de Factura',
            self::PurchaseInvoice => 'Factura de Compra',
            self::DispatchGuide => 'Guía de Despacho',
            self::DebitNote => 'Nota de Débito',
            self::CreditNote => 'Nota de Crédito',
        };
    }

    /**
     * Returns the schema the document is bound to, or null if it not supported by it.
     */
    public function schemaXsd(): ?string
    {
        return match ($this) {
            self::Invoice,
            self::InvoiceExempt,
            self::Receipt,
            self::ExemptReceipt,
            self::InvoiceLiquidation,
            self::PurchaseInvoice,
            self::DispatchGuide,
            self::DebitNote,
            self::CreditNote => 'DTE_v10.xsd',
            default => null
        };
    }
}
