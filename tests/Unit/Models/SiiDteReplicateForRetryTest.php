<?php

namespace Tests\Unit\Models;

use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDtePayload;
use Tests\DatabaseTestCase;

class SiiDteReplicateForRetryTest extends DatabaseTestCase
{
    public function test_replicates_for_retry(): void
    {
        $original = SiiDte::factory()->create([
            'status' => DteStatus::Rejected,
            'pack_retries' => 3,
            'rejected_at' => now(),
            'folio' => 123,
        ]);

        $payload = new SiiDtePayload(['data' => ['some' => 'data']]);
        $original->payload()->save($payload);

        $clone = $original->replicateForRetry();
        $clone->load('payload');

        static::assertNotEquals($original->id, $clone->id);
        static::assertEquals(DteStatus::Pending, $clone->status);
        static::assertNull($clone->folio);
        static::assertNull($clone->sii_caf_id);
        static::assertNull($clone->sii_dte_envelope_id);
        static::assertNull($clone->rejected_at);
        static::assertEquals(0, $clone->pack_retries);
        static::assertEquals(['some' => 'data'], $clone->payload->data);
    }
}
