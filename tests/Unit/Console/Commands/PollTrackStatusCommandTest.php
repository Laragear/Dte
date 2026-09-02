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

    public function test_caps_poll_delay_to_fifteen_minutes(): void
    {
        $queue = Queue::fake();

        $this->app->make('config')->set('dte.queue.track.connection', 'database');
        $this->app->make('config')->set('dte.queue.track.name', 'dte-queue');
        $this->app->make('config')->set('dte.envelopes.backoff_seconds', 2000);

        SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '111111111',
            'updated_at' => now()->subMinutes(35),
        ]);

        SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Uploaded,
            'track_id' => '222222222',
            'updated_at' => now()->subMinutes(35),
        ]);

        $this
            ->artisan('dte:poll-track-status')
            ->assertSuccessful();

        $jobs = $queue->pushed(PollEnvelopeTrackIdJob::class);

        static::assertCount(2, $jobs);

        // Without the cap, at least one job would be delayed 2000 seconds.
        $maxDelay = $jobs->max(fn(PollEnvelopeTrackIdJob $job) => (int) $job->delay);

        static::assertLessThanOrEqual(905, $maxDelay);
        static::assertGreaterThan(0, $maxDelay);
    }
}
