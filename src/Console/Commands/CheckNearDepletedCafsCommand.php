<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Events\CafExpiring;
use Laragear\Dte\Events\CafNearDepleted;
use Laragear\Dte\Models\SiiCaf;

class CheckNearDepletedCafsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:check-cafs {--threshold= : The percentage threshold to consider a CAF as near depleted (defaults to config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for active CAFs nearing folio depletion or expiration and dispatch events';

    /**
     * Execute the console command.
     */
    public function handle(Dispatcher $event, Repository $config, DateFactory $date): int
    {
        $threshold = (float) ($this->option('threshold') ?? $config->get('dte.caf.depletion_threshold', 10));

        SiiCaf::query()
            ->where(function ($query) use ($date) {
                $query->whereNull('expires_on')->orWhere('expires_on', '>', $date->now());
            })
            ->chunk(100, function ($cafs) use ($threshold, $event, $date) {
                foreach ($cafs as $caf) {
                    $total = $caf->folio_to - $caf->folio_from + 1;
                    $remaining = max(0, $caf->folio_to - $caf->folio_current + 1);

                    $percentage = ($remaining / $total) * 100;

                    if ($percentage <= $threshold) {
                        $event->dispatch(new CafNearDepleted($caf, $remaining, $percentage));
                    }

                    if ($caf->expires_on) {
                        $daysLeft = (int) $date->now()->startOfDay()->diffInDays($caf->expires_on->startOfDay());

                        if ($daysLeft >= 0 && $daysLeft <= 7) {
                            $event->dispatch(new CafExpiring($caf, $daysLeft));
                        }
                    }
                }
            });

        $this->info('CAF depletion and expiration check completed.');

        return self::SUCCESS;
    }
}
