<?php

namespace Laragear\Dte\Actions\CreateEnvelope\Pipes;

use Closure;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Enums\EnvelopeStatus;
use LogicException;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class InitializeEnvelope
{
    /**
     * Create an Initialize Envelope pipe instance.
     */
    public function __construct(
        protected TemporaryDirectory $temporary,
    ) {
        //
    }

    /**
     * Prepare a pending envelope and its temporary file.
     *
     * @param  Closure(Assembly): Assembly  $next
     */
    public function handle(Assembly $assembly, Closure $next): Assembly
    {
        if ($assembly->envelope->status !== EnvelopeStatus::Pending) {
            throw new LogicException('Only pending DTE envelopes may be assembled.');
        }

        $assembly->envelope->transitionTo(EnvelopeStatus::Assembling);

        $assembly->temporary = $this->temporary->create();
        $assembly->path = $assembly->temporary->path('envelope.xml');

        return $next($assembly);
    }
}
