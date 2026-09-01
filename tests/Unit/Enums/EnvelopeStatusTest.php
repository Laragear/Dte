<?php

namespace Tests\Unit\Enums;

use Laragear\Dte\Enums\EnvelopeStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function array_column;

class EnvelopeStatusTest extends TestCase
{
    public static function providesTerminalStates(): array
    {
        return [
            EnvelopeStatus::Pending->value => [EnvelopeStatus::Pending, false],
            EnvelopeStatus::Assembling->value => [EnvelopeStatus::Assembling, false],
            EnvelopeStatus::Signing->value => [EnvelopeStatus::Signing, false],
            EnvelopeStatus::Signed->value => [EnvelopeStatus::Signed, false],
            EnvelopeStatus::Sending->value => [EnvelopeStatus::Sending, false],
            EnvelopeStatus::Uploaded->value => [EnvelopeStatus::Uploaded, false],
            EnvelopeStatus::Accepted->value => [EnvelopeStatus::Accepted, true],
            EnvelopeStatus::Rejected->value => [EnvelopeStatus::Rejected, true],
            EnvelopeStatus::Failed->value => [EnvelopeStatus::Failed, true],
        ];
    }

    public function test_defines_envelope_states(): void
    {
        static::assertSame(
            [
                'Pending' => 'pending',
                'Assembling' => 'assembling',
                'Signing' => 'signing',
                'Signed' => 'signed',
                'Sending' => 'sending',
                'Uploaded' => 'uploaded',
                'Accepted' => 'accepted',
                'Rejected' => 'rejected',
                'Failed' => 'failed',
            ],
            array_column(EnvelopeStatus::cases(), 'value', 'name'),
        );
        static::assertSame(EnvelopeStatus::Pending, EnvelopeStatus::DEFAULT);
    }

    #[DataProvider('providesTerminalStates')]
    public function test_identifies_terminal_states(EnvelopeStatus $status, bool $isTerminal): void
    {
        static::assertSame($status->isTerminalState(), $isTerminal);
    }
}
