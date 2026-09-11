<?php

namespace Laragear\Dte\Facades;

use DateTimeImmutable;
use Illuminate\Support\Facades\Facade;
use Laragear\Dte\Builders\CreditNoteBuilder;
use Laragear\Dte\Contracts\Issuable;
use Laragear\Dte\Contracts\Receivable;
use Laragear\Dte\Contracts\Itemable;
use Laragear\Dte\Data\IssuerData;
use Laragear\Dte\Data\Item;
use Laragear\Dte\Data\ReceiverData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\ModifierTarget;
use Laragear\Dte\Enums\ReferenceType;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Testing\Fakes\FakeCreditNoteBuilder;
use Laragear\Dte\Testing\Fakes\FakesBuilder;
use Laragear\Rut\Rut;

/**
 * @see CreditNoteBuilder
 *
 * @method static CreditNoteBuilder issuedBy(Issuable|IssuerData $issuer)
 * @method static CreditNoteBuilder receivedBy(Receivable|ReceiverData|Rut|string $receiver, ?string $name = null)
 * @method static CreditNoteBuilder issuedOn(\DateTimeImmutable $date)
 * @method static CreditNoteBuilder addItem(Itemable|Item|string $item, int|array|null $total = null, ?bool $isExempt = null)
 * @method static CreditNoteBuilder withNetAmountIndicator(int $indicator)
 * @method static CreditNoteBuilder nonBillableAmount(int $amount)
 * @method static CreditNoteBuilder globalDiscount(float|int $value, bool $isPercent = false, ModifierTarget $target = ModifierTarget::DEFAULT, ?string $description = null)
 * @method static CreditNoteBuilder globalSurcharge(float|int $value, bool $isPercent = false, ModifierTarget $target = ModifierTarget::DEFAULT, ?string $description = null)
 * @method static CreditNoteBuilder hydrate(SiiDte $dte)
 * @method static CreditNoteBuilder annul(DteType|ReferenceType|SiiDte|string|int $documentType, ?string $folio = null, ?DateTimeImmutable $date = null, string $reason = 'Anula documento')
 * @method static CreditNoteBuilder amend(DteType|ReferenceType|SiiDte|string|int $documentType, ?string $folio = null, ?DateTimeImmutable $date = null, string $reason = 'Corrige texto')
 * @method static \Laragear\Dte\Enums\DteType documentType()
 * @method static IssuerData issuer()
 * @method static ?ReceiverData receiver()
 * @method static ?SiiDte dte()
 * @method static list<Item> items()
 * @method static int itemAmount(Item $item)
 * @method static int netAmount()
 * @method static int exemptAmount()
 * @method static int taxAmount()
 * @method static int totalAmount()
 * @method static array totals()
 * @method static array references()
 * @method static ?int netAmountIndicator()
 * @method static int getNonBillableAmount()
 * @method static array globalModifiers()
 * @method static array attributes()
 * @method static array payloadData()
 * @method static SiiDte create(mixed $sync = false)
 * @method static SiiDte update(mixed $sync = false)
 * @method static void validate()
 *
 * @method FakeCreditNoteBuilder fake()
 */
class SiiCreditNote extends Facade
{
    use FakesBuilder;

    protected static string $fakeClass = FakeCreditNoteBuilder::class;

    /**
     * {@inheritDoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return CreditNoteBuilder::class;
    }

    /**
     * Assert the expected number of credit notes were created.
     */
    public static function assertCreated(?int $times = null): void
    {
        FakeCreditNoteBuilder::assertCreated($times);
    }

    /**
     * Assert no credit notes were created.
     */
    public static function assertNotCreated(): void
    {
        FakeCreditNoteBuilder::assertNotCreated();
    }

    /**
     * Return all created credit notes.
     *
     * @return list<SiiDte>
     */
    public static function created(): array
    {
        return FakeCreditNoteBuilder::created();
    }

    /**
     * Return the last created credit note, or null.
     */
    public static function lastCreated(): ?SiiDte
    {
        return FakeCreditNoteBuilder::lastCreated();
    }
}
