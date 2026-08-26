<?php

namespace Tests\Unit\Actions\CreateEnvelope\Pipes;

use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Actions\CreateEnvelope\Pipes\BuildCaratulaHeader;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use LogicException;
use RuntimeException;
use Tests\DatabaseTestCase;
use UnexpectedValueException;

class BuildCaratulaHeaderTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('dte.envelopes.max_documents', 10);
        $this->app->make('config')->set('dte.issuer.resolution_date', '2023-01-01');
        $this->app->make('config')->set('dte.issuer.resolution_number', 1234);
    }

    public function test_build_caratula_header_streams_elements_successfully(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'sender_rut' => Rut::parse('22222222-2'),
            'document_type' => 33,
            'resolution_date' => '2023-01-01',
            'resolution_number' => 1234,
        ]);

        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);

        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);
        $assembly->path = tempnam(sys_get_temp_dir(), 'dte_');

        try {
            $this
                ->pipeline(CreateEnvelope::class)
                ->isolatePipe(BuildCaratulaHeader::class)
                ->send($assembly)
                ->assertPassable(function (Assembly $result) {
                    static::assertNotNull($result->writer);

                    $xml = $this->app->make(Filesystem::class)->get($result->path);

                    static::assertStringContainsString(
                        '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" version="1.0">',
                        $xml,
                    );
                    static::assertStringContainsString('<SetDTE ID="SetDoc">', $xml);
                    static::assertStringContainsString('<Caratula version="1.0">', $xml);
                    static::assertStringContainsString('<RutEmisor>11111111-1</RutEmisor>', $xml);
                    static::assertStringContainsString('<RutEnvia>22222222-2</RutEnvia>', $xml);
                    static::assertStringContainsString('<FchResol>2023-01-01</FchResol>', $xml);
                    static::assertStringContainsString('<NroResol>1234</NroResol>', $xml);
                    static::assertStringContainsString(
                        '<SubTotDTE><TpoDTE>33</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>',
                        $xml,
                    );

                    return true;
                });
        } finally {
            unlink($assembly->path);
        }
    }

    public function test_throws_if_no_documents(): void
    {
        $envelope = SiiDteEnvelope::factory()->create();
        $assembly = new Assembly($envelope);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE envelope must contain at least one signed document.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_if_too_many_documents(): void
    {
        $this->app->make('config')->set('dte.envelopes.max_documents', 1);

        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
        ]);

        $dte1 = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);
        $dte2 = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);

        $envelope->dtes()->saveMany([$dte1, $dte2]);

        $assembly = new Assembly($envelope);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE envelope exceeds the configured document limit.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_if_invalid_documents_in_envelope(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
        ]);

        // Wrong status
        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Building,
        ]);

        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The DTE envelope documents must share its issuer, type, and signed state.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_on_invalid_resolution_date(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'resolution_date' => '2023-01-01',
        ]);
        $envelope->resolution_date = null;

        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);

        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);
        $assembly->path = tempnam(sys_get_temp_dir(), 'dte_');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('The issuer resolution date must use YYYY-MM-DD format.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_on_invalid_resolution_number(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'resolution_date' => '2023-01-01',
            'resolution_number' => 1234,
        ]);
        $envelope->resolution_number = -1;

        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);

        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);
        $assembly->path = tempnam(sys_get_temp_dir(), 'dte_');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('The issuer resolution number must be a non-negative integer.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_if_bad_max_documents_config(): void
    {
        $this->app->make('config')->set('dte.envelopes.max_documents', -1);

        $envelope = SiiDteEnvelope::factory()->create();
        $dte = SiiDte::factory()->create();
        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('The envelope document limit must be a positive integer.');

        $this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }

    public function test_throws_if_path_cannot_be_opened(): void
    {
        $envelope = SiiDteEnvelope::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
        ]);

        $dte = SiiDte::factory()->create([
            'issuer_rut' => Rut::parse('11111111-1'),
            'document_type' => 33,
            'status' => DteStatus::Signed,
        ]);

        $envelope->dtes()->save($dte);

        $assembly = new Assembly($envelope);
        $assembly->path = '/non-existent-dir/envelope.xml';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to open the temporary envelope XML file.');

        @$this
            ->pipeline(CreateEnvelope::class)
            ->isolatePipe(BuildCaratulaHeader::class)
            ->send($assembly);
    }
}
