<?php

namespace Tests\Unit\Actions\CreateEnvelope\Pipes;

use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Actions\CreateEnvelope\Pipes\PersistEnvelopePayload;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Tests\DatabaseTestCase;

class PersistEnvelopePayloadTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_persist_envelope_payload_saves_xml_and_transitions_status(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Assembling,
        ]);

        $assembly = new Assembly($envelope);

        $document = $this->app->make(XmlDomFactory::class)->document();
        $document->loadXML('<?xml version="1.0" encoding="ISO-8859-1"?><EnvioDTE><SetDTE/></EnvioDTE>');
        $assembly->document = $document;

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(PersistEnvelopePayload::class)
            ->send($assembly)
            ->assertPassable(function (Assembly $result) use ($envelope, $document) {
                static::assertEquals(EnvelopeStatus::Signed, $result->envelope->status);
                $this->assertDatabaseHas('sii_dte_envelope_payloads', [
                    'sii_dte_envelope_id' => $envelope->id,
                    'xml' => $document->saveXML(),
                ]);

                return true;
            });
    }

    public function test_skips_persistence_for_ephemeral_assembly(): void
    {
        $envelope = SiiDteEnvelope::factory()->make();
        $assembly = new Assembly($envelope, ephemeral: true);

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(PersistEnvelopePayload::class)
            ->send($assembly);

        static::assertFalse($assembly->envelope->relationLoaded('payload'));
    }
}
