<?php

namespace Laragear\Dte\Actions\RcvParsing\Pipes;

use Closure;
use Laragear\Dte\Actions\RcvParsing\ParsingContext;
use Laragear\Dte\Support\StreamProxy;

class ApplyEncodingStreamFilter
{
    /**
     * Create a new Apply Encoding Stream Filter instance.
     */
    public function __construct(
        protected StreamProxy $stream,
    ) {
        //
    }

    /**
     * Handle the given context.
     *
     * @param  Closure(ParsingContext):ParsingContext  $next
     */
    public function handle(ParsingContext $context, Closure $next): mixed
    {
        // Enforce parsing off ISO-8859-1 or Windows-1252 into pure UTF-8 to keep arrays cleanly mapping accents
        $this->stream->appendFilter($context->stream, 'convert.iconv.ISO-8859-1/UTF-8');

        return $next($context);
    }
}
