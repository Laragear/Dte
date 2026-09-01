<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\DateFactory;
use Illuminate\Support\LazyCollection;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Jobs\PollEnvelopeTrackIdJob;
use Laragear\Dte\Models\SiiDteEnvelope;

class PollTrackStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:poll-track-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queries SII for TrackID status, updating envelope to Accepted or Rejected';

    /**
     * Execute the console command.
     */
    public function handle(Repository $config, DateFactory $date): int
    {
        // Don't poll envelopes that were just uploaded or just polled recently. We use
        // the same holding minutes config as a reasonable interval (e.g., 30 mins).
        $pollingIntervalMinutes = $config->get('dte.envelopes.max_holding_minutes', 30);
        $backoffSeconds = $config->get('dte.envelopes.backoff_seconds', 60);

        $queueConnection = $config->get('dte.queue.track.connection');
        $queueName = $config->get('dte.queue.track.name', 'default');

        $delayCounter = 0;
        $dispatchedCount = 0;

        foreach ($this->envelopes($date, $pollingIntervalMinutes) as $envelope) {
            $delay = $delayCounter * $backoffSeconds;

            // We advance the `updated_at` timestamp by the delay. This ensures that if there's a
            // massive queue of polling jobs (e.g., 500 jobs × 60s = 8.3 hours), the cron won't
            // pick up this envelope again until 30 minutes *after* this is scheduled to run.
            $envelope->setAttribute('updated_at', $date->now()->addSeconds($delay));

            $envelope->save();

            PollEnvelopeTrackIdJob::dispatch($envelope)
                ->onConnection($queueConnection)
                ->onQueue($queueName)
                ->delay($delay);

            $delayCounter++;
            $dispatchedCount++;
        }

        $this->info("Dispatched {$dispatchedCount} polling jobs for uploaded envelopes.");

        return self::SUCCESS;
    }

    /**
     * Retrieve pollable envelopes.
     *
     * @return LazyCollection<int, SiiDteEnvelope>
     */
    protected function envelopes(DateFactory $date, int $pollingIntervalMinutes): LazyCollection
    {
        return SiiDteEnvelope::query()
            ->where('status', EnvelopeStatus::Uploaded)
            ->whereNotNull('track_id')
            ->where('updated_at', '<=', $date->now()->subMinutes($pollingIntervalMinutes))
            ->cursor();
    }
}
