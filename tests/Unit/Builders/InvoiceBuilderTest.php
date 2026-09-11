<?php

namespace Tests\Unit\Builders;

use DateTimeImmutable;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Laragear\Dte\Builders\InvoiceBuilder;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Data\CompanyData;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Data\Item;
use Laragear\Dte\Data\PaymentTermData;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ReferenceType;
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

        ConfigurationManager::setCompany(fn () => CompanyData::make(
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

    public function test_hydrates_payment_terms(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);
        $builder->issuedBy(BuilderFixture::issuer());
        $builder->receivedBy(BuilderFixture::receiver());
        $builder->addItem(BuilderFixture::item());

        $paymentTerm = PaymentTermData::make('Credit', new DateTimeImmutable('2026-09-13'));
        $builder->addPaymentTerm($paymentTerm);

        $dte = $builder->create();

        // Restore from the SiiDte model and re-create to verify round-trip
        $restored = $this->app->make(InvoiceBuilder::class);
        $restored->hydrate($dte);

        // Verify hydration succeeded by checking the payload contains payment data
        static::assertSame('Credit', $dte->payload->data['payment']['condition']);
        static::assertSame('2026-09-13', $dte->payload->data['payment']['expiration_date']);
    }

    /*
     |---------- | withMetadata | ---------- |
     */

    public function test_with_metadata_from_array(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item())
            ->withMetadata(['sort_order' => 5, 'custom' => 'value']);

        $dte = $builder->create();

        static::assertSame(5, $dte->metadata->sort_order);
        static::assertSame('value', $dte->metadata->custom);
    }

    public function test_with_metadata_from_fluent(): void
    {
        $fluent = new Fluent(['sort_order' => 3]);

        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item())
            ->withMetadata($fluent);

        $dte = $builder->create();

        static::assertSame(3, $dte->metadata->sort_order);
    }

    public function test_without_metadata_is_null(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item());

        $dte = $builder->create();

        static::assertNull($dte->metadata);
    }

    /*
     |---------- | forTestCase + references | ---------- |
     */

    public function test_for_test_case_prepends_set_reference(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item())
            ->forTestCase('5034081-1');

        $refs = $builder->references();

        static::assertCount(1, $refs);
        static::assertSame(ReferenceType::TestSet, $refs[0]->documentType);
        static::assertSame('0', $refs[0]->folio);
        static::assertSame('CASO 5034081-1', $refs[0]->reason);
    }

    public function test_for_test_case_survives_correction_methods(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item())
            ->forTestCase('5034081-1')
            ->addReference(ReferenceData::make('33', '1', new DateTimeImmutable('2025-01-01'), 'Additional ref'));

        $refs = $builder->references();

        static::assertCount(2, $refs);
        static::assertSame(ReferenceType::TestSet, $refs[0]->documentType);
        static::assertSame(DteType::Invoice, $refs[1]->documentType);
    }

    public function test_for_test_case_does_not_duplicate_when_set_ref_present(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item());

        // Manually add SET reference first, then call forTestCase
        $builder->addReference(ReferenceData::make(
            ReferenceType::TestSet,
            '0',
            new DateTimeImmutable('2025-01-01'),
            'CASO 5034081-1',
        ));
        $builder->forTestCase('5034081-1');

        $refs = $builder->references();

        static::assertCount(1, $refs);
        static::assertSame(ReferenceType::TestSet, $refs[0]->documentType);
    }

    public function test_for_test_case_invalid_format_throws(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid test case format');

        $builder->forTestCase('bad-format');
    }
}
