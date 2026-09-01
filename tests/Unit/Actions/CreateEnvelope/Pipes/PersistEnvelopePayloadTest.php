<?php

namespace Tests\Unit\Actions\CreateEnvelope\Pipes;

use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Actions\CreateEnvelope\Pipes\PersistEnvelopePayload;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Mockery;
use RuntimeException;
use Tests\DatabaseTestCase;
use Tests\Unit\Actions\CreateEnvelope\Pipes\Fixtures\NoReadStreamWrapper;

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

        $path = tempnam(sys_get_temp_dir(), 'dte_');
        file_put_contents($path, '<EnvioDTE><SetDTE></SetDTE></EnvioDTE>');
        $assembly->path = $path;

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

        unlink($path);
    }

    public function test_skips_persistence_for_ephemeral_assembly(): void
    {
        $envelope = SiiDteEnvelope::factory()->make();
        $assembly = new Assembly($envelope, ephemeral: true);

        // We use an empty mock for filesystem, which will fail if methods are called
        $this->mock(Filesystem::class, static function (Mockery\MockInterface $mock): void {
            $mock->expects('put')->never();
            $mock->expects('get')->never();
        });

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(PersistEnvelopePayload::class)
            ->send($assembly);

        static::assertFalse($assembly->envelope->relationLoaded('payload'));
    }

    /*
     |--------------------------------------------------------------------------
     | Angry paths
     |--------------------------------------------------------------------------
     */

    public function test_throws_when_file_write_fails(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        $assembly = new Assembly($envelope);

        // Use a path that cannot be written to (directory doesn't exist)
        $assembly->path = '/non/existent/path/file.xml';

        $document = $this->app->make(XmlDomFactory::class)->document();
        $document->loadXML('<?xml version="1.0" encoding="ISO-8859-1"?><EnvioDTE><SetDTE/></EnvioDTE>');
        $assembly->document = $document;

        $this
            ->mock(Filesystem::class)
            ->expects('put')
            ->with($assembly->path, Mockery::type('string'))
            ->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to write the signed envelope XML.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(PersistEnvelopePayload::class)
            ->send($assembly);
    }

    public function test_throws_when_file_read_fails(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Assembling,
        ]);

        $assembly = new Assembly($envelope);

        $document = $this->app->make(XmlDomFactory::class)->document();
        $document->loadXML('<?xml version="1.0"?><EnvioDTE></EnvioDTE>');
        $assembly->document = $document;

        if (!in_array('noread', stream_get_wrappers())) {
            stream_wrapper_register('noread', NoReadStreamWrapper::class);
        }

        $path = 'noread://test';
        $assembly->path = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to read the signed envelope XML.');

        set_error_handler(function () {
            return true;
        });

        try {
            $this
                ->pipeline(CreateEnvelope::class)
                ->isolatePipe(PersistEnvelopePayload::class)
                ->send($assembly);
        } finally {
            restore_error_handler();
            stream_wrapper_unregister('noread');
        }
    }
}
