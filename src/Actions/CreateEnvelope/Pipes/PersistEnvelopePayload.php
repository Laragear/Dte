<?php

namespace Laragear\Dte\Actions\CreateEnvelope\Pipes;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Enums\EnvelopeStatus;
use RuntimeException;

class PersistEnvelopePayload
{
    /**
     * Create a new Persist Envelope Payload instance.
     */
    public function __construct(protected Filesystem $file)
    {
        //
    }

    /**
     * Persist the signed envelope XML and final state.
     *
     * @param  Closure(Assembly): Assembly  $next
     */
    public function handle(Assembly $assembly, Closure $next): Assembly
    {
        if ($assembly->ephemeral) {
            return $next($assembly);
        }

        $path = $assembly->requirePath();
        $xml = $assembly->requireDocument()->saveXML();

        if ($xml === false || $this->file->put($path, $xml) === false) {
            throw new RuntimeException('Unable to write the signed envelope XML.');
        }

        try {
            $contents = $this->file->get($path);
        } catch (FileNotFoundException $e) {
            throw new RuntimeException('Unable to read the signed envelope XML.', previous: $e);
        }

        $payload = $assembly->envelope->payload()->updateOrCreate([], ['xml' => $contents]);

        $assembly->envelope->setRelation('payload', $payload);
        $assembly->envelope->transitionTo(EnvelopeStatus::Signed);

        return $next($assembly);
    }
}
