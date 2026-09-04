<?php

namespace Laragear\Dte\Certification\Interchange\Pipes;

use Closure;
use Laragear\Dte\Actions\InboundDte\ProcessInboundDte as ProcessInboundDtePipeline;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInterchangeLog;

class ProcessInboundDte
{
    /**
     * Create a new pipe instance.
     */
    public function __construct(
        protected ProcessInboundDtePipeline $pipeline,
    ) {
        //
    }

    /**
     * Handle the incoming interchange data.
     */
    public function handle(InterchangeData $data, Closure $next): InterchangeData
    {
        $this->pipeline->handle($data->emailData);

        $log = SiiInterchangeLog::where('message_id', $data->emailData->messageId)->first();

        $data->inboundDocument = SiiInboundDocument::where('sii_interchange_log_id', $log->id)->first();

        return $next($data);
    }
}
