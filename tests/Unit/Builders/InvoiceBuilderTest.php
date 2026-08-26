<?php

namespace Tests\Unit\Builders;

use Laragear\Dte\Builders\InvoiceBuilder;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Data\CompanyData;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Data\Item;
use Laragear\Dte\Enums\DteType;
use LogicException;
use Override;
use Tests\DatabaseTestCase;
use Tests\Unit\Builders\Fixtures\BuilderFixture;

class InvoiceBuilderTest extends DatabaseTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        ConfigurationManager::setCompany(fn() => CompanyData::make(
            IssuerData::make(
                '76.123.456-0',
                'Test Company',
                'Software',
                ['620100'],
                'Test Address 123',
                'Santiago',
                '2025-01-01',
                76000,
                'Santiago',
                '+56212345678',
                'test@example.com',
                'Casa Matriz',
            ),
            '76.123.456-0',
        ));
    }

    /*
     |--------------------------------------------------------------------------
     | Happy Paths
     |--------------------------------------------------------------------------
     */

    public function test_creates_an_exempt_invoice_with_an_amount_override(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item())
            ->asExempt(2000);

        $dte = $builder->create();

        static::assertSame(DteType::InvoiceExempt, $dte->document_type);
        static::assertSame(0, $dte->amount_net);
        static::assertSame(2000, $dte->amount_exempt);
        static::assertSame(0, $dte->amount_taxes);
        static::assertSame(2000, $dte->amount_total);
        static::assertTrue($dte->payload->data['tax_exempt']);
        static::assertSame(2000, $dte->payload->data['exempt_amount_override']);
    }

    public function test_creates_an_exempt_invoice_calculating_amounts_from_items(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(Item::make('Exempt service', 1000, exempt: true))
            ->asExempt();

        $dte = $builder->create();

        static::assertSame(DteType::InvoiceExempt, $dte->document_type);
        static::assertSame(0, $dte->amount_net);
        static::assertSame(1000, $dte->amount_exempt);
        static::assertSame(0, $dte->amount_taxes);
        static::assertSame(1000, $dte->amount_total);
        static::assertTrue($dte->payload->data['tax_exempt']);
        static::assertNull($dte->payload->data['exempt_amount_override']);
    }

    /*
     |--------------------------------------------------------------------------
     | Sad Paths
     |--------------------------------------------------------------------------
     */

    public function test_throws_when_taxable_invoice_contains_only_exempt_items(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(Item::make('Exempt service', 1000, exempt: true));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('An invoice containing only exempt items must use document type 34.');

        $builder->create();
    }

    public function test_throws_when_totals_are_negative(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(Item::make('Negative service', -1000));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE totals cannot be negative.');

        $builder->create();
    }
}
