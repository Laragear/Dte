<?php

namespace Tests\Unit\Enums;

use Laragear\Dte\Enums\DteStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function array_column;

class DteStatusTest extends TestCase
{
    public static function providesTerminalStates(): array
    {
        return [
            DteStatus::Pending->value => [DteStatus::Pending, false],
            DteStatus::Building->value => [DteStatus::Building, false],
            DteStatus::RequiresCaf->value => [DteStatus::RequiresCaf, false],
            DteStatus::Signing->value => [DteStatus::Signing, false],
            DteStatus::Signed->value => [DteStatus::Signed, false],
            DteStatus::Sent->value => [DteStatus::Sent, false],

            DteStatus::Accepted->value => [DteStatus::Accepted, true],
            DteStatus::Rejected->value => [DteStatus::Rejected, true],
            DteStatus::Failed->value => [DteStatus::Failed, true],
            DteStatus::Annulled->value => [DteStatus::Annulled, true],
        ];
    }

    public function test_defines_document_states(): void
    {
        static::assertSame(
            [
                'Pending' => 'pending',
                'Building' => 'building',
                'RequiresCaf' => 'requires_caf',
                'Signing' => 'signing',
                'Signed' => 'signed',
                'Sent' => 'sent',
                'Accepted' => 'accepted',
                'Rejected' => 'rejected',
                'Failed' => 'failed',
                'Annulled' => 'annulled',
            ],
            array_column(DteStatus::cases(), 'value', 'name'),
        );
        static::assertSame(DteStatus::Pending, DteStatus::DEFAULT);
    }

    #[DataProvider('providesTerminalStates')]
    public function test_identifies_terminal_states(DteStatus $status, bool $isTerminal): void
    {
        static::assertSame($status->isTerminalState(), $isTerminal);
    }
}
