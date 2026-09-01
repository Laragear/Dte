<?php

namespace Tests\Unit\Models;

use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Models\SiiDteEnvelope;
use Tests\TestCase;

class SiiDteEnvelopeTest extends TestCase
{
    public function test_accepted_with_repairs_helpers(): void
    {
        $envelope = new SiiDteEnvelope;

        $envelope->status = EnvelopeStatus::Pending;
        $envelope->repairs = null;
        static::assertFalse($envelope->isAcceptedWithRepairs());
        static::assertTrue($envelope->isNotAcceptedWithRepairs());

        $envelope->status = EnvelopeStatus::Accepted;
        $envelope->repairs = null;
        static::assertFalse($envelope->isAcceptedWithRepairs());
        static::assertTrue($envelope->isNotAcceptedWithRepairs());

        $envelope->status = EnvelopeStatus::Accepted;
        $envelope->repairs = [];
        static::assertFalse($envelope->isAcceptedWithRepairs());
        static::assertTrue($envelope->isNotAcceptedWithRepairs());

        $envelope->status = EnvelopeStatus::Accepted;
        $envelope->repairs = ['rechazados' => 1];
        static::assertTrue($envelope->isAcceptedWithRepairs());
        static::assertFalse($envelope->isNotAcceptedWithRepairs());
    }
}
