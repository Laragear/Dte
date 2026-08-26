<?php

namespace Tests\Unit\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Jobs\PollEnvelopeTrackIdJob;
use Laragear\Dte\Models\SiiDteEnvelope;
use Tests\DatabaseTestCase;

class PollTrackStatusCommandTest extends DatabaseTestCase
{
    use RefreshDatabase;

    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_dispatches_jobs_for_uploaded_envelopes_with_track_id(): void
    {
        $queue = Queue::fake();

        $this->app->make('config')->set('dte.queue.track.connection', 'database');
        $this->app->make('config')->set('dte.queue.track.name', 'dte-queue');

        // Should be queued
        $envelope1 = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '123456789',
            'updated_at' => now()->subMinutes(35),
        ]);

        $envelope2 = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '987654321',
            'updated_at' => now()->subMinutes(35),
        ]);

        // Should be ignored (no track id)
        SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => null,
            'updated_at' => now()->subMinutes(35),
        ]);

        // Should be ignored (wrong status)
        SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Pending,
            'track_id' => '555555555',
            'updated_at' => now()->subMinutes(35),
        ]);

        // Should be ignored (too new)
        SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '666666666', // Different from above
            'updated_at' => now(), // Right now
        ]);

        $this
            ->artisan('dte:poll-track-status')
            ->expectsOutput('Dispatched 2 polling jobs for uploaded envelopes.')
            ->assertSuccessful();

        $queue->assertPushed(PollEnvelopeTrackIdJob::class, 2);

        $queue->assertPushed(
            PollEnvelopeTrackIdJob::class,
            static function (PollEnvelopeTrackIdJob $job) use ($envelope1): bool {
                return $job->envelope->id === $envelope1->id;
            },
        );

        $queue->assertPushed(
            PollEnvelopeTrackIdJob::class,
            static function (PollEnvelopeTrackIdJob $job) use ($envelope2): bool {
                return $job->envelope->id === $envelope2->id;
            },
        );
    }
}
