<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Laragear\Dte\Actions\CreateEnvelope\CreateEnvelope;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Events\EnvelopeSending;
use Laragear\Dte\Events\EnvelopeSent;
use Laragear\Dte\Gateways\BoletaRestGateway;
use Laragear\Dte\Gateways\UploadGateway;
use Laragear\Dte\Models\SiiDteEnvelope;

class ProcessAndSendEnvelopeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:process-envelope {envelope_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process an envelope, signs it, and send it to the SII';

    /**
     * Execute the console command.
     */
    public function handle(
        Dispatcher $event,
        CreateEnvelope $create,
        UploadGateway $upload,
        BoletaRestGateway $boletaUpload
    ): int {
        $envelope = $create->send(SiiDteEnvelope::findOrFail($this->argument('envelope_id')))->thenReturn()->envelope;

        $event->dispatch(new EnvelopeSending($envelope));

        // If the DTE is a receipt (Boleta), upload it using its own exclusive uploader.
        if ($envelope->type === 'boleta') {
            $trackId = $boletaUpload->upload($envelope, $envelope->payload->xml);
        } else {
            $trackId = $upload->upload($envelope, $envelope->payload->xml);
        }

        $envelope->update([
            'track_id' => $trackId,
            'status' => EnvelopeStatus::Uploaded,
        ]);

        $event->dispatch(new EnvelopeSent($envelope));

        $this->info("Envelope [{$envelope->getKey()}] processed and sent with Track ID: {$trackId}");

        return self::SUCCESS;
    }
}
