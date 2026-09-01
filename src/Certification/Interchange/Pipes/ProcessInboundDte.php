<?php

namespace Laragear\Dte\Certification\Interchange\Pipes;

use Closure;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Dte\Services\InboundDteProcessor;

class ProcessInboundDte
{
    /**
     * Create a new pipe instance.
     */
    public function __construct(
        protected InboundDteProcessor $processor,
    ) {
        //
    }

    /**
     * Handle the incoming interchange data.
     */
    public function handle(InterchangeData $data, Closure $next): InterchangeData
    {
        $this->processor->process($data->emailData);

        $log = SiiInterchangeLog::where('message_id', $data->emailData->messageId)->first();

        $data->inboundDocument = SiiInboundDocument::where('sii_interchange_log_id', $log->id)->first();

        return $next($data);
    }
}
