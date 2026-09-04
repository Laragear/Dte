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
    public function handle(InboundEmailData $email): void
    {
        $data = new InboundDteData($email);

        SiiInterchangeLog::query()
            ->getConnection()
            ->transaction(function () use ($data): void {
                $this->send($data)->through($this->pipes)->thenReturn();
            });
    }
}
