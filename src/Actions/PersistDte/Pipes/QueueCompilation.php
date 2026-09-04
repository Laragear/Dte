<?php

namespace Laragear\Dte\Actions\PersistDte\Pipes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleContract;
use Laragear\Dte\Actions\PersistDte\DteData;
use function value;

class QueueCompilation
{
    /**
     * Create a new Queue Compilation instance.
     */
    public function __construct(
        protected ConsoleContract $artisan,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Handle the incoming DTE Persistence.
     *
     * @param  Closure(DteData):DteData  $next
     */
    public function handle(DteData $data, Closure $next): DteData
    {
        // If the DTE should be compiled immediately, we will call Artisan in-sync for that.
        // Otherwise, we will call the command but queue it for async compilation with the
        // model primary key and use the connection and queue from the app configuration.
        if (value($data->sync, $data->dte, $data->builder)) {
            $this->artisan->call('dte:compile', ['dte_id' => $data->dte]);
        } else {
            $this->artisan
                ->queue('dte:compile', ['dte_id' => $data->dte->getKey()])
                ->onConnection($this->config->get('dte.queue.dte.connection'))
                ->onQueue($this->config->get('dte.queue.dte.name'));
        }

        return $next($data);
    }
}
