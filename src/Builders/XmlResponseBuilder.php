<?php

namespace Laragear\Dte\Builders;

use DateTimeImmutable;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Dte\Xml\XmlValidator;
use Laragear\Rut\Rut;
use XMLWriter;

class XmlResponseBuilder
{
    /**
     * Create a new XML Response Builder instance.
     */
    public function __construct(
        protected XmlDomFactory $xml,
        protected XmlSigner $signer,
        protected XmlValidator $validator,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Build a signed Formato IC response for an individual DTE.
     */
    public function forDocument(
        SiiInboundDocument $dte,
        Rut $responder,
        int $responseId,
        int $shipmentCode,
        int $status,
        string $message,
        DigitalCertificate $certificate,
        ?DateTimeImmutable $signedAt = null,
        ?int $reasonCode = null,
    ): string {
        $signedAt ??= $this->date->now('America/Santiago')->toDateTimeImmutable();

        $writer = $this->xml->writer();
        $writer->openMemory();
        $writer->startDocument('1.0', 'ISO-8859-1');
        $writer->startElementNS(null, 'RespuestaDTE', XmlDomFactory::XML_NAMESPACE);
        $writer->writeAttribute('version', '1.0');

        $resultID = "Respuesta-$responseId";

        $writer->startElement('Resultado');
        $writer->writeAttribute('ID', $resultID);

        $this->appendHeader($writer, $dte, $responder, $responseId, $signedAt);
        $this->appendDocumentResult($writer, $dte, $shipmentCode, $status, $message, $reasonCode);

        $writer->endElement(); // Resultado
        $writer->endElement(); // RespuestaDTE
        $writer->endDocument();

        $xmlString = $writer->outputMemory();

        $signedXml = $this->signer->signString($xmlString, $certificate, [$resultID]);
        $this->validator->verifySignature($signedXml);

        return $signedXml;
    }

    /**
     * Append the header (Caratula) section for the DTE response.
     */
    protected function appendHeader(
        XMLWriter $writer,
        SiiInboundDocument $dte,
        Rut $responder,
        int $responseId,
        DateTimeImmutable $signedAt,
    ): void {
        $writer->startElement('Caratula');
        $writer->writeAttribute('version', '1.0');
        $writer->writeElement('RutResponde', $responder->formatBasic());
        $writer->writeElement('RutRecibe', $dte->issuer_rut->formatBasic());
        $writer->writeElement('IdRespuesta', (string) $responseId);
        $writer->writeElement('NroDetalles', '1');
        $writer->writeElement('TmstFirmaResp', $this->timestamp($signedAt));
        $writer->endElement();
    }

    /**
     * Append the individual DTE result details and status to the XML writer.
     */
    protected function appendDocumentResult(
        XMLWriter $writer,
        SiiInboundDocument $dte,
        int $shipmentCode,
        int $status,
        string $message,
        ?int $reasonCode,
    ): void {
        $writer->startElement('ResultadoDTE');
        $this->appendDocument($writer, $dte);
        $writer->writeElement('CodEnvio', (string) $shipmentCode);
        $writer->writeElement('EstadoDTE', (string) $status);
        $writer->writeElement('EstadoDTEGlosa', $message);

        if ($reasonCode !== null) {
            $writer->writeElement('CodRchDsc', (string) $reasonCode);
        }
        $writer->endElement();
    }

    /**
     * Append the core document details to the XML writer.
     */
    protected function appendDocument(XMLWriter $writer, SiiInboundDocument $dte): void
    {
        $writer->writeElement('TipoDTE', (string) $dte->document_type->value);
        $writer->writeElement('Folio', (string) $dte->folio);
        $writer->writeElement('FchEmis', $dte->issued_on->format('Y-m-d'));
        $writer->writeElement('RUTEmisor', $dte->issuer_rut->formatBasic());
        $writer->writeElement('RUTRecep', $dte->receiver_rut->formatBasic());
        $writer->writeElement('MntTotal', (string) $dte->amount_total);
    }

    /**
     * Format the given date into an XML timestamp string.
     */
    protected function timestamp(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}
