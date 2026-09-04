<?php

namespace Laragear\Dte\Certification\Pipes;

use Closure;
use Laragear\Dte\Actions\CompileDte\Compile;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Certification\TestingSet\TestSetData;

class EnsureDtesCompiled
{
    /**
     * Create a new Ensure Dtes Compiled instance.
     */
    public function __construct(
        protected Compile $compile,
    ) {
        //
    }

    /**
     * Handle the incoming certification data.
     */
    public function handle(SimulationData|TestSetData $data, Closure $next): SimulationData|TestSetData
    {
        $data->dtes->each(function ($dte): void {
            if ($dte->payload?->xml === null) {
                $this->compile->forDte($dte);
                $dte->load('payload');
            }
        });

        return $next($data);
    }
}
