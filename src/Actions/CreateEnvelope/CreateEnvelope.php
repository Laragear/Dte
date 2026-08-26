<?php

namespace Laragear\Dte\Actions\CreateEnvelope;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Rut\Rut;

/**
 * @method Assembly thenReturn()
 */
class CreateEnvelope extends Pipeline
{
    /**
     * The envelope assembly stages.
     *
     * @var class-string[]
     */
    protected $pipes = [
        Pipes\InitializeEnvelope::class,
        Pipes\BuildCaratulaHeader::class,
        Pipes\EmbedDteNodes::class,
        Pipes\CanonicalizeEnvelope::class,
        Pipes\ApplyEnvelopeSignature::class,
        Pipes\PersistEnvelopePayload::class,
    ];

    /**
     * Assemble the envelope.
     */
    public function forEnvelope(SiiDteEnvelope $envelope): Assembly
    {
        $assembly = new Assembly($envelope);

        try {
            return $this->send($assembly)->thenReturn();
        } finally {
            $assembly->cleanup();
        }
    }

    /**
     * Assemble an ephemeral envelope for interchange sharing.
     */
    public function forSharing(SiiDteEnvelope $envelope, Rut $receiverRut): Assembly
    {
        $assembly = new Assembly($envelope, $receiverRut, ephemeral: true);

        try {
            return $this->send($assembly)->thenReturn();
        } finally {
            $assembly->cleanup();
        }
    }
}
