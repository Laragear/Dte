<?php

namespace Laragear\Dte\Certification\PrintSample\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;

class EnsureDteExist
{
    use Concerns\QueriesLatestDte;

    /**
     * Handle the incoming print sample data.
     */
    public function handle(PrintSampleData $data, Closure $next): PrintSampleData
    {
        if ($this->query($data->rut, $data->dteIds)->doesntExist()) {
            throw new ManuallyFailedException('No DTEs found. You need to create the DTEs first (Step 1).');
        }

        return $next($data);
    }
}
