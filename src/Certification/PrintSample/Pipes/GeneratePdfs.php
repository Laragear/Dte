<?php

namespace Laragear\Dte\Certification\PrintSample\Pipes;

use Closure;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Pdf\PdfBuilder;

class GeneratePdfs
{
    use Concerns\QueriesLatestDte;

    /**
     * Create a new Generate PDFs instance.
     */
    public function __construct(
        protected PdfBuilder $builder,
    ) {
        //
    }

    /**
     * Handle the incoming print sample data.
     */
    public function handle(PrintSampleData $data, Closure $next): PrintSampleData
    {
        foreach ($this->query($data->rut, $data->dteIds)->lazyById(5) as $dte) {
            $data->pdfs[] = $this->builder->forDte($dte)->generate();
        }

        return $next($data);
    }
}
