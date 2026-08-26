<?php

namespace Laragear\Dte\Builders;

use DateTimeImmutable;
use Illuminate\Support\DateFactory;
use InvalidArgumentException;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Data\CessionData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Rut\Rut;
use XMLWriter;

use function in_array;
use function is_string;

class AecBuilder
{
    public const string XML_NAMESPACE = 'http://www.sii.cl/SiiDte';

    public function __construct(
        protected XmlDomFactory $xml,
        protected XmlSigner $signer,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Build a signed Archivo Electrónico de Cesión.
     */
    public function build(
        SiiDte $dte,
        CessionData $cession,
        string $receiptXml,
        Rut|string $authorizedSigner,
        string $authorizedName,
        string $cedentEmail,
        DigitalCertificate $certificate,
        ?DateTimeImmutable $signedAt = null,
    ): string {
        $authorizedSigner = is_string($authorizedSigner) ? Rut::parse($authorizedSigner) : $authorizedSigner;

        $this->validate($dte);
        $signedAt ??= $this->date->now('America/Santiago')->toDateTimeImmutable();

        $writer = $this->xml->writer();
        $writer->openMemory();
        $writer->startDocument('1.0', 'ISO-8859-1');
        $writer->startElementNS(null, 'AEC', static::XML_NAMESPACE);
        $writer->writeAttribute('version', '1.0');

        $aecID = 'AEC-'.$dte->document_type->value.'-'.$dte->folio;
        $dtecID = 'DTECedido-'.$dte->document_type->value.'-'.$dte->folio;
        $cessionID = 'Cesion-1-'.$dte->folio;

        $writer->startElement('DocumentoAEC');
        $writer->writeAttribute('ID', $aecID);

        $writer->startElement('Caratula');
        $writer->writeAttribute('version', '1.0');
        $writer->writeElement('RutCedente', $dte->issuer_rut->formatBasic());
        $writer->writeElement('RutCesionario', $cession->assigneeRut->formatBasic());
        $writer->writeElement('TmstFirmaEnvio', $this->timestamp($signedAt));
        $writer->endElement(); // Caratula

        $writer->startElement('Cesiones');
        $this->writeTransferredDte($writer, $dte, $receiptXml, $dtecID, $signedAt);
        $this->writeCession($writer, $dte, $cession, $authorizedSigner, $authorizedName, $cedentEmail, $cessionID,
            $signedAt);
        $writer->endElement(); // Cesiones

        $writer->endElement(); // DocumentoAEC
        $writer->endElement(); // AEC
        $writer->endDocument();

        $xmlString = $writer->outputMemory();

        return $this->signer->signString($xmlString, $certificate, [$dtecID, $cessionID, $aecID]);
    }

    protected function writeTransferredDte(
        XMLWriter $writer,
        SiiDte $dte,
        string $receiptXml,
        string $dtecID,
        DateTimeImmutable $signedAt,
    ): void {
        $writer->startElement('DTECedido');
        $writer->writeAttribute('version', '1.0');
        $writer->startElement('DocumentoDTECedido');
        $writer->writeAttribute('ID', $dtecID);

        $writer->writeRaw($this->sourceElementStr($this->dteXml($dte), 'DTE'));
        $writer->writeRaw($this->sourceElementStr($receiptXml, 'Recibo'));

        $writer->writeElement('TmstFirma', $this->timestamp($signedAt));
        $writer->endElement(); // DocumentoDTECedido
        $writer->endElement(); // DTECedido
    }

    protected function writeCession(
        XMLWriter $writer,
        SiiDte $dte,
        CessionData $cession,
        Rut $authorizedSigner,
        string $authorizedName,
        string $cedentEmail,
        string $cessionID,
        DateTimeImmutable $signedAt,
    ): void {
        $writer->startElement('Cesion');
        $writer->writeAttribute('version', '1.0');
        $writer->startElement('DocumentoCesion');
        $writer->writeAttribute('ID', $cessionID);

        $writer->writeElement('SeqCesion', '1');
        $this->writeDteIdentity($writer, $dte);
        $this->writeCedent($writer, $dte, $authorizedSigner, $authorizedName, $cedentEmail);
        $this->writeAssignee($writer, $cession);
        $this->writeTerms($writer, $cession, $dte, $signedAt);

        $writer->endElement(); // DocumentoCesion
        $writer->endElement(); // Cesion
    }

    protected function writeDteIdentity(XMLWriter $writer, SiiDte $dte): void
    {
        $writer->startElement('IdDTE');
        $writer->writeElement('TipoDTE', (string) $dte->document_type->value);
        $writer->writeElement('RUTEmisor', $dte->issuer_rut->formatBasic());
        $writer->writeElement('RUTReceptor', $dte->receiver_rut->formatBasic());
        $writer->writeElement('Folio', (string) $dte->folio);
        $writer->writeElement('FchEmis', $dte->issued_on->format('Y-m-d'));
        $writer->writeElement('MntTotal', (string) $dte->amount_total);
        $writer->endElement(); // IdDTE
    }

    protected function writeCedent(
        XMLWriter $writer,
        SiiDte $dte,
        Rut $authorizedSigner,
        string $authorizedName,
        string $cedentEmail,
    ): void {
        $issuer = $dte->payload?->data['issuer'] ?? [];
        $writer->startElement('Cedente');
        $writer->writeElement('RUT', $dte->issuer_rut->formatBasic());
        $writer->writeElement('RazonSocial', (string) ($issuer['legal_name'] ?? ''));
        $writer->writeElement('Direccion', (string) ($issuer['address'] ?? ''));
        $writer->writeElement('eMail', $cedentEmail);
        $writer->startElement('RUTAutorizado');
        $writer->writeElement('RUT', $authorizedSigner->formatBasic());
        $writer->writeElement('Nombre', $authorizedName);
        $writer->endElement(); // RUTAutorizado
        $writer->endElement(); // Cedente
    }

    protected function writeAssignee(XMLWriter $writer, CessionData $cession): void
    {
        $writer->startElement('Cesionario');
        $writer->writeElement('RUT', $cession->assigneeRut->formatBasic());
        $writer->writeElement('RazonSocial', $cession->assigneeName);
        $writer->writeElement('Direccion', $cession->assigneeAddress);
        $writer->writeElement('eMail', $cession->assigneeEmail);
        $writer->endElement(); // Cesionario
    }

    protected function writeTerms(
        XMLWriter $writer,
        CessionData $cession,
        SiiDte $dte,
        DateTimeImmutable $signedAt,
    ): void {
        $writer->writeElement('MontoCesion', (string) $cession->amount);
        $writer->writeElement('UltimoVencimiento', $cession->lastDueDate->format('Y-m-d'));

        if ($cession->terms !== null) {
            $writer->writeElement('OtrasCondiciones', $cession->terms);
        }

        $email = $dte->payload?->data['receiver']['email'] ?? null;

        if (is_string($email) && $email !== '') {
            $writer->writeElement('eMailDeudor', $email);
        }

        $writer->writeElement('TmstCesion', $this->timestamp($signedAt));
    }

    protected function validate(SiiDte $dte): void
    {
        $supported = [DteType::Invoice, DteType::InvoiceExempt, DteType::InvoiceLiquidation, DteType::PurchaseInvoice];

        if (! in_array($dte->document_type, $supported, true)) {
            throw new InvalidArgumentException('The DTE type cannot be transferred through an AEC.');
        }

        if ($dte->folio === null || $dte->issued_on === null || $dte->payload?->xml === null) {
            throw new InvalidArgumentException('The AEC requires a compiled DTE with a folio and signed XML payload.');
        }
    }

    protected function dteXml(SiiDte $dte): string
    {
        return
            $dte->payload?->xml ?? throw new InvalidArgumentException(
                'The AEC requires a compiled DTE with a folio and signed XML payload.',
            );
    }

    protected function sourceElementStr(string $xml, string $name): string
    {
        $document = $this->xml->document();

        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException("The [$name] XML payload is invalid.");
        }

        $element = $this->xml->xpath($document)
            ->query("//*[local-name()='$name']")
            ?->item(0);

        if (! $element) {
            throw new InvalidArgumentException("The XML payload does not contain a [$name] element.");
        }

        return $document->saveXML($element);
    }

    protected function timestamp(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}
