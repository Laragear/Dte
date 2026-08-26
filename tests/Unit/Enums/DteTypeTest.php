<?php

namespace Tests\Unit\Enums;

use Laragear\Dte\Enums\DteType;
use PHPUnit\Framework\TestCase;
use function array_column;

class DteTypeTest extends TestCase
{
    public function test_defines_supported_document_types(): void
    {
        static::assertSame(
            [
                'Invoice' => 33,
                'ExemptInvoice' => 34,
                'Receipt' => 39,
                'ExemptReceipt' => 41,
                'InvoiceLiquidation' => 43,
                'PurchaseInvoice' => 46,
                'DispatchGuide' => 52,
                'DebitNote' => 56,
                'CreditNote' => 61,
            ],
            array_column(DteType::cases(), 'value', 'name'),
        );
        static::assertSame(DteType::Invoice, DteType::DEFAULT);
    }
}
