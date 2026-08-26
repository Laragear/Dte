<?php

namespace Laragear\Dte\Certification\PrintSample\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Pdf\PdfBuilder;

class GeneratePdfs
{
    /**
     * Create a new Generate Pdfs instance.
     */
    public function __construct(protected PdfBuilder $builder)
    {
        //
    }

    /**
     * Handle the incoming print sample data.
     */
    public function handle(PrintSampleData $data, Closure $next): PrintSampleData
    {
        $hours = $data->hours;

        $query = SiiDte::where([
            'issuer_num' => $data->rut->num,
            'issuer_vd' => $data->rut->vd,
        ])->where('created_at', '>=', now()->subHours($hours));

        if ($query->doesntExist()) {
            throw new ManuallyFailedException("No DTEs found in the last {$hours} hours. You need to create the DTEs first (Step 1).");
        }

        foreach ($query->lazyById(5) as $dte) {
            $data->pdfs[] = $this->builder->forDte($dte)->generate();
        }

        return $next($data);
    }
}
