<?php

namespace Tests\Unit\Models\Scopes;

use Generator;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiCaf;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Models\SiiInboundDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentTypeScopesTest extends TestCase
{
    /**
     * @return Generator<string, array{class-string, string}>
     */
    public static function models(): Generator
    {
        yield 'CAF' => [SiiCaf::class, 'sii_cafs'];
        yield 'DTE' => [SiiDte::class, 'sii_dtes'];
        yield 'envelope' => [SiiDteEnvelope::class, 'sii_dte_envelopes'];
        yield 'inbound document' => [SiiInboundDocument::class, 'sii_inbound_documents'];
    }

    public static function providesDocumentTypeScopes(): iterable
    {
        return [
            'Invoice' => [DteType::Invoice, 'invoices'],
            'Exempt Invoice' => [DteType::InvoiceExempt, 'exemptInvoices'],
            'Receipt' => [DteType::Receipt, 'receipts'],
            'Invoice Liquidation' => [DteType::InvoiceLiquidation, 'invoiceLiquidations'],
            'Purchase Invoice' => [DteType::PurchaseInvoice, 'purchaseInvoices'],
            'Dispatch Guide' => [DteType::DispatchGuide, 'dispatchGuides'],
            'Debit Note' => [DteType::DebitNote, 'debitNotes'],
            'Credit Note' => [DteType::CreditNote, 'creditNotes'],
        ];
    }

    protected function binding(string $sql): int
    {
        return (int) substr($sql, strrpos($sql, ' ') + 1);
    }

    /** @param  class-string  $model */
    #[DataProvider('models')]
    public function test_models_scope_queries_by_document_type(string $model, string $table): void
    {
        static::assertSame(
            "select * from \"$table\" where \"document_type\" = ".DteType::CreditNote->value,
            $model::query()->whereDocumentType(DteType::CreditNote)->toRawSql(),
        );
    }

    #[DataProvider('providesDocumentTypeScopes')]
    public function test_exposes_a_scope_for_each_supported_document_type(DteType $type, string $method): void
    {
        static::assertSame($type->value, $this->binding(SiiDte::query()->{$method}()->toRawSql()));
    }

    /** @param  class-string  $model */
    #[DataProvider('models')]
    public function test_models_cast_document_types_to_enum(string $model, string $table): void
    {
        static::assertSame(
            DteType::Invoice,
            (new $model)->setRawAttributes(['document_type' => DteType::Invoice->value])->document_type,
        );
    }
}
