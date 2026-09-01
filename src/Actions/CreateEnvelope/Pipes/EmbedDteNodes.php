<?php

namespace Laragear\Dte\Actions\CreateEnvelope\Pipes;

use Closure;
use DOMElement;
use Illuminate\Support\LazyCollection;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Models\SiiDtePayload;
use RuntimeException;

class EmbedDteNodes
{
    /**
     * Stream each signed DTE into the temporary envelope file.
     *
     * @param  Closure(Assembly): Assembly  $next
     */
    public function handle(Assembly $assembly, Closure $next): Assembly
    {
        $writer = $assembly->requireWriter();

        foreach ($this->payloads($assembly) as $payload) {
            $writer->writeRaw($this->dteXml($payload));

            $assembly->embeddedDocuments++;

            $writer->flush();
        }

        if ($assembly->embeddedDocuments !== $assembly->expectedDocuments) {
            throw new RuntimeException('Every envelope document must contain a signed XML payload.');
        }

        $this->closeEnvelope($assembly);

        return $next($assembly);
    }

    /**
     * Close and flush the streamed envelope.
     */
    protected function closeEnvelope(Assembly $assembly): void
    {
        $writer = $assembly->requireWriter();

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        $writer->flush();

        $assembly->writer = null;
    }

    /**
     * Cursor over the envelope payloads without loading the batch into memory.
     *
     * @return LazyCollection<int, SiiDtePayload>
     */
    protected function payloads(Assembly $assembly): LazyCollection
    {
        return $assembly->envelope->dtePayloads()
            ->when($assembly->targetReceiverRut, fn($q, $rut) => $q->where('sii_dtes.receiver_num', $rut->num))
            ->orderBy('sii_dtes.id')
            ->cursor();
    }

    /**
     * Return one valid DTE root for streaming.
     */
    protected function dteXml(SiiDtePayload $payload): string
    {
        $document = $payload->toDomDocument();

        $root = $document->documentElement;

        if (!$root instanceof DOMElement || $root->localName !== 'DTE') {
            throw new RuntimeException('An envelope payload does not contain a DTE root element.');
        }

        $xml = $document->saveXML($root);

        return $xml !== false
            ? $xml
            : throw new RuntimeException('Unable to serialize a DTE payload into the envelope.');
    }
}
