<?php

namespace Tests\Unit\Builders;

use DateTimeImmutable;
use Generator;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Builders\DocumentBuilder;
use Laragear\Dte\Builders\InvoiceBuilder;
use Laragear\Dte\Builders\ReceiptBuilder;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Contracts\Issuable;
use Laragear\Dte\Contracts\Receivable;
use Laragear\Dte\Data\CompanyData;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Data\Item;
use Laragear\Dte\Data\PaymentTermData;
use Laragear\Dte\Data\ReferenceData;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Events\DteCreated;
use Laragear\Dte\Events\DteCreating;
use Laragear\Dte\Models\SiiDte;
use Laragear\Rut\Rut;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use OverflowException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\DatabaseTestCase;
use Tests\Unit\Builders\Fixtures\BuilderFixture;

class DocumentBuilderTest extends DatabaseTestCase
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

    public static function providesTruthySyncConditions(): Generator
    {
        yield 'bool' => [true];
        yield 'integer' => [1];
        yield 'callback' => [static fn(SiiDte $dte, ReceiptBuilder $builder) => true];
    }

    /**
     * Assert the initial persisted document state.
     */
    protected function assertPendingDocument(SiiDte $dte, string $issuer, string $receiver): void
    {
        static::assertTrue($dte->exists);
        static::assertTrue($dte->payload->exists);
        static::assertSame(DteStatus::Pending, $dte->status);
        static::assertNull($dte->folio);
        static::assertSame(
            ['net' => 1800, 'exempt' => 0, 'tax' => 342, 'total' => 2142],
            $dte->payload->data['totals'],
        );
        $issuer = Rut::parse($issuer);
        $receiver = Rut::parse($receiver);
        $this->assertDatabaseHas('sii_dtes', [
            'issuer_num' => $issuer->num,
            'issuer_vd' => $issuer->vd,
            'receiver_num' => $receiver->num,
            'receiver_vd' => $receiver->vd,
            'document_type' => DteType::Invoice->value,
            'amount_total' => 2142,
            'status' => DteStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('sii_dte_payloads', ['sii_dte_id' => $dte->getKey()]);
    }

    public function test_fluently_creates_a_pending_document_raw_payload_and_queues_compilation(): void
    {
        $queue = Queue::fake();

        $events = Event::fake([DteCreating::class, DteCreated::class]);
        $builder = $this->app->make(InvoiceBuilder::class);
        $issuer = BuilderFixture::issuer();
        $receiver = BuilderFixture::receiver();
        $date = new DateTimeImmutable('2026-08-13');

        static::assertSame($builder, $builder->issuedBy($issuer));
        static::assertSame($builder, $builder->receivedBy($receiver));
        static::assertSame($builder, $builder->issuedOn($date));
        static::assertSame($builder, $builder->addItem(BuilderFixture::item()));

        $this
            ->mock(Compile::class)
            ->expects('forDte')
            ->withArgs(function (SiiDte $dte) use ($receiver): bool {
                return $dte->receiver_rut->isEqual($receiver->rut);
            });

        $dte = $builder->create();

        static::assertPendingDocument($dte, $issuer->rut->formatRaw(), $receiver->rut->formatRaw());
        static::assertSame('Consulting service', $dte->payload->data['items'][0]['name']);
        static::assertSame('2026-08-13', $dte->payload->data['issued_on']);
        $events->assertDispatched(DteCreating::class, fn(DteCreating $event): bool => $event->builder === $builder);
        $events->assertDispatched(DteCreated::class, fn(DteCreated $event): bool => $event->dte->is($dte));

        $queue->assertPushed(QueuedCommand::class, function (QueuedCommand $job) {
            $this->app->call($job->handle(...));

            return true;
        });
    }

    #[DataProvider('providesTruthySyncConditions')]
    public function test_can_build_document_sync(mixed $truthy): void
    {
        $queue = Queue::fake();

        $this
            ->mock(Compile::class)
            ->expects('forDte')
            ->withArgs(function (SiiDte $dte) {
                $dte->status = DteStatus::Signed;

                return true;
            });

        $dte = $this->app
            ->make(ReceiptBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->addItem(BuilderFixture::item())
            ->create($truthy);

        static::assertSame(DteStatus::Signed, $dte->status);

        $queue->assertNothingPushed();
    }

    public function test_can_get_the_receiver_when_configured(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);

        static::assertNull($builder->receiver());

        $receiver = BuilderFixture::receiver();

        $builder->receivedBy($receiver);

        static::assertSame($receiver, $builder->receiver());
    }

    public function test_issuable_as_issuer(): void
    {
        $fixture = BuilderFixture::issuer();

        $issuable = Mockery::mock(Issuable::class, function (MockInterface $mock) use ($fixture): void {
            $mock->expects('toIssuer')->andReturn($fixture);
        });

        $builder = $this->app->make(InvoiceBuilder::class);

        $builder->issuedBy($issuable);

        static::assertSame($fixture, $builder->issuer());
    }

    public function test_receivable_as_receiver(): void
    {
        $fixture = BuilderFixture::receiver();

        $receivable = Mockery::mock(Receivable::class, function (MockInterface $mock) use ($fixture): void {
            $mock->expects('toReceiver')->andReturn($fixture);
        });

        $builder = $this->app->make(InvoiceBuilder::class);

        $builder->receivedBy($receivable);

        static::assertSame($fixture, $builder->receiver());
    }

    public function test_default_references_is_empty(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);
        static::assertSame([], $builder->references());
    }

    public function test_accumulates_item_taxes_with_same_code(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);
        $builder->issuedBy(BuilderFixture::issuer());
        $builder->receivedBy(BuilderFixture::receiver());

        // Two items with the same tax code (e.g., 14 for VAT, normally 19%)
        $item1 = new Item('test1', 1000, 1, taxes: [14 => 190]);
        $item2 = new Item('test2', 2000, 1, taxes: [14 => 380, 15 => 100]);

        $builder->addItem($item1);
        $builder->addItem($item2);

        $dte = $builder->create();

        // Total tax for code 14 should be 570, code 15 should be 100.
        static::assertSame(570, $dte->taxes[14]);
        static::assertSame(100, $dte->taxes[15]);
    }

    public function test_references_returns_empty_array(): void
    {
        $builder = $this->mock(DocumentBuilder::class)->makePartial();

        static::assertSame([], $builder->references());
    }

    public function test_adds_payment_term_and_serializes(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);
        $builder->issuedBy(BuilderFixture::issuer());
        $builder->receivedBy(BuilderFixture::receiver());
        $builder->addItem(new Item('test', 100, 1));

        $paymentTerm = PaymentTermData::make('Credit', new DateTimeImmutable('2026-09-13'));
        $builder->addPaymentTerm($paymentTerm);

        $dte = $builder->create();

        static::assertSame('Credit', $dte->payload->data['payment']['condition']);
        static::assertSame('2026-09-13', $dte->payload->data['payment']['expiration_date']);
    }

    public function test_throws_when_issuer_is_missing(): void
    {
        $this->app->instance(ConfigurationManager::class, clone $this->app->make(ConfigurationManager::class));
        $this->app->make(ConfigurationManager::class)->setIssuerResolver(null);
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(BuilderFixture::item());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No Issuer resolver has been registered.');

        $builder->create();
    }

    public function test_throws_when_receiver_is_missing(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->addItem(BuilderFixture::item());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE receiver has not been configured.');

        $builder->create();
    }

    public function test_throws_when_items_are_missing(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE must contain at least one item.');

        $builder->create();
    }

    public function test_throws_when_totals_are_negative(): void
    {
        $builder = $this->app
            ->make(InvoiceBuilder::class)
            ->issuedBy(BuilderFixture::issuer())
            ->receivedBy(BuilderFixture::receiver())
            ->addItem(new Item('test', -100, 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE totals cannot be negative.');

        $builder->create();
    }

    public function test_throws_when_discount_is_invalid(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The item discount percentage must be between 0 and 100.');

        $builder->addItem(new Item('test', 100, 1, discountPercentage: 110));
    }

    public function test_throws_when_too_many_items(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);

        for ($i = 0; $i < 60; $i++) {
            $builder->addItem('item', 100);
        }

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageIs('A DTE cannot contain more than 60 item lines.');

        $builder->addItem('item 61', 100);
    }

    public function test_handles_exempt_documents_and_modifiers_and_references(): void
    {
        $builder = new class extends DocumentBuilder {
            public $mockGlobal = [];

            public $mockItems = [];

            public $typeMock = DteType::InvoiceExempt;

            public function __construct()
            {
            }

            protected function buildDocument(): array
            {
                return [];
            }

            public function documentType(): DteType
            {
                return $this->typeMock;
            }

            public function items(): array
            {
                return $this->mockItems;
            }

            public function globalModifiers(): array
            {
                return $this->mockGlobal;
            }

            public function addItem(Item $item): static
            {
                return $this;
            }

            public function totals(): array
            {
                return ['net' => 1000, 'exempt' => 100, 'tax' => 190];
            }

            public function callValidateSpecific(): void
            {
                $this->validateSpecific();
            }

            public function callCalculatedTotals(): array
            {
                return $this->calculatedTotals();
            }

            public function callReferenceData(ReferenceData $ref): array
            {
                return $this->referenceData($ref);
            }

            public function callReceivedBy(mixed $receiver, mixed $name)
            {
                return $this->receivedBy($receiver, $name);
            }
        };

        $builder->mockGlobal = [
            ['type' => 'D', 'value_type' => '%', 'value' => 10, 'target' => 1],
            ['type' => 'R', 'value_type' => '$', 'value' => 100, 'target' => 2],
        ];

        $item1 = new Item('name', 1, 1, taxes: [15 => 50, 13 => 100]);
        $builder->mockItems = [$item1];

        // Test Exempt Invoice tax
        $totals = $builder->callCalculatedTotals();
        static::assertSame(0, $totals['tax']);

        // Test Regular Invoice tax
        $builder->typeMock = DteType::Invoice;
        $totals2 = $builder->callCalculatedTotals();
        static::assertEquals(209, $totals2['tax']); // 1100 * 0.19

        $ref = new ReferenceData(DteType::Invoice, '123', new DateTimeImmutable, 'test', 1);
        $compiledRef = $builder->callReferenceData($ref);
        static::assertSame(33, $compiledRef['document_type']);
        static::assertSame('123', $compiledRef['folio']);

        $ref2 = new ReferenceData('33', '123', new DateTimeImmutable, 'test', 1);
        $compiledRef2 = $builder->callReferenceData($ref2);
        static::assertEquals('33', $compiledRef2['document_type']);

        $builder->callReceivedBy('12345678-5', 'Some Receiver');
        $builder->callValidateSpecific();
    }

    public function test_uses_dynamic_issuer_if_not_explicitly_issued_by(): void
    {
        $builder = $this->app->make(InvoiceBuilder::class);

        ConfigurationManager::resolveIssuerUsing(function () {
            return IssuerData::make(
                '76.123.456-0',
                'Dynamic Company',
                'Activity',
                '1234',
                'Addr',
                'Com',
                '2025-01-01',
                80,
                telephone: '123456',
            );
        });

        $issuer = $builder->issuer();
        static::assertEquals('761234560', $issuer->rut->formatRaw());
        static::assertEquals('Dynamic Company', $issuer->legalName);

        // We can't call protected issuerData, but we can verify it doesn't throw.
    }

    public function test_throws_exception_if_no_issuer_and_no_dynamic_issuer(): void
    {
        $this->app->instance(ConfigurationManager::class, clone $this->app->make(ConfigurationManager::class));

        $this->app->make(ConfigurationManager::class)->setIssuerResolver(null);

        $builder = $this->app->make(InvoiceBuilder::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No Issuer resolver has been registered.');

        $builder->issuer();
    }
}
