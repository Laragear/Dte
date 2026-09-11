<?php

namespace Laragear\Dte\Actions\InboundDte;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Models\SiiInterchangeLog;

/**
 * @method InboundDteData thenReturn()
 */
class ProcessInboundDte extends Pipeline
{
    /**
     * The pipeline stages.
     *
     * @var class-string[]
     */
    protected $pipes = [
        Pipes\ValidateXmlStructure::class,
        Pipes\ParseInboundDocument::class,
        Pipes\CreateInterchangeLog::class,
        Pipes\ProcessEnvioDteDocuments::class,
        Pipes\ProcessRespuestaDteDocuments::class,
    ];

    /**
     * Process an inbound DTE email payload.
     *
     * @context Transaction
     */
    public function forEmail(InboundEmailData $email): InboundDteData
    {
        return SiiInterchangeLog::query()
            ->getConnection()
            ->transaction(fn () => $this->send(new InboundDteData($email))->through($this->pipes)->thenReturn());
    }
}
