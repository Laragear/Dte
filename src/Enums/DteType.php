<?php

namespace Laragear\Dte\Enums;

enum DteType: int
{
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
     * Check if the DTE Type is not a receipt.
     */
    public function isNotReceipt(): bool
    {
        return !$this->isReceipt();
    }
}
