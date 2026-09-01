<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use XMLWriter;
use function number_format;
use function round;
use function rtrim;

class BuildXml
{
    /**
     * Create a Build XML pipe instance.
     */
    public function __construct(
        protected XmlDomFactory $xml,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Render the unsigned DTE document from the builder payload.
     *
     * @param  Closure(Compilation): Compilation  $next
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $writer = $this->xml->writer();
        $writer->openMemory();
        $writer->startDocument('1.0', 'ISO-8859-1');

        $writer->startElementNS(null, 'DTE', XmlDomFactory::XML_NAMESPACE);
        $writer->writeAttribute('version', '1.0');

        $this->appendDocument($compilation, $writer);

        $writer->endElement(); // DTE
        $writer->endDocument();

        $xmlString = $writer->outputMemory();

        $document = $this->xml->document(encoding: 'ISO-8859-1');
        $document->loadXML($xmlString, LIBXML_NONET);
        $document->encoding = 'ISO-8859-1';

        $compilation->document = $document;

        return $next($compilation);
    }

    /**
     * Append the Documento node and all unsigned content.
     */
    protected function appendDocument(Compilation $compilation, XMLWriter $writer): void
    {
        $dte = $compilation->dte;
        $data = $compilation->payload()->data;

        $writer->startElement('Documento');
        $writer->writeAttribute('ID', "F{$dte->folio}T{$dte->document_type->value}");

        $this->appendHeader($writer, $data, (int) $dte->folio);
        $this->appendItems($writer, $data['items']);
        if (!empty($data['global_modifiers'])) {
            $this->appendGlobalModifiers($writer, $data['global_modifiers']);
        }
        $this->appendReferences($writer, $data['references']);

        if (!empty($data['transport'])) {
            $this->appendTransport($writer, $data['transport']);
        }

        $writer->writeElement('TmstFirma', $this->date->now('America/Santiago')->format('Y-m-d\TH:i:s'));

        $writer->endElement(); // Documento
    }

    /**
     * Append the <Transporte> block for Guías de Despacho (Type 52).
     *
     * @param  array<string, mixed>  $transport
     */
    protected function appendTransport(XMLWriter $writer, array $transport): void
    {
        $writer->startElement('Transporte');

        $this->optionalElement($writer, 'Patente', $transport['vehicle_plate'] ?? null);
        $this->optionalElement($writer, 'PatenteVehiculo', $transport['trailer_plate'] ?? null);
        $this->optionalElement($writer, 'RUTTrans', $transport['carrier_rut'] ?? null);

        if (!empty($transport['driver_rut'])) {
            $writer->startElement('Chofer');
            $writer->writeElement('RUT', $transport['driver_rut']);
            $this->optionalElement($writer, 'Nombre', $transport['driver_name'] ?? null);
            $writer->endElement(); // Chofer
        }

        $this->optionalElement($writer, 'DirDest', $transport['destination_address'] ?? null);
        $this->optionalElement($writer, 'CmnaDest', $transport['destination_commune'] ?? null);
        $this->optionalElement($writer, 'CiudadDest', $transport['destination_city'] ?? null);

        $writer->endElement(); // Transporte
    }

    /**
     * Append the document header sections.
     *
     * @param  array<string, mixed>  $data
     */
    protected function appendHeader(XMLWriter $writer, array $data, int $folio): void
    {
        $writer->startElement('Encabezado');

        $this->appendIdentification($writer, $data, $folio);
        $this->appendIssuer($writer, $data['issuer']);
        $this->appendReceiver($writer, $data['receiver']);
        $this->appendTotals($writer, $data['totals'], $data['taxes'] ?? []);

        $writer->endElement(); // Encabezado
    }

    /**
     * Append document identification.
     *
     * @param  array<string, mixed>  $data
     */
    protected function appendIdentification(XMLWriter $writer, array $data, int $folio): void
    {
        $writer->startElement('IdDoc');

        $writer->writeElement('TipoDTE', $data['document_type']);
        $writer->writeElement('Folio', (string) $folio);
        $writer->writeElement('FchEmis', $data['issued_on']);
        $this->appendPaymentTerms($writer, $data['payment'] ?? null);
        $this->optionalElement($writer, 'IndTraslado', $data['ind_traslado'] ?? null);
        $this->optionalElement($writer, 'TipoDespacho', $data['tipo_despacho'] ?? null);

        $writer->endElement(); // IdDoc
    }

    /**
     * Append payment condition and due date when configured.
     *
     * <FmaPago> values: 1=Contado, 2=Crédito, 3=Sin costo
     *
     * @param  array{condition: int, expiration_date: string}|null  $paymentTerms
     */
    protected function appendPaymentTerms(XMLWriter $writer, ?array $paymentTerms): void
    {
        if ($paymentTerms === null) {
            return;
        }

        $writer->writeElement('FmaPago', (string) $paymentTerms['condition']);

        if (!empty($paymentTerms['expiration_date'])) {
            $writer->writeElement('FchVenc', $paymentTerms['expiration_date']);
        }
    }

    /**
     * Append issuer information.
     *
     * @param  array<string, mixed>  $issuer
     */
    protected function appendIssuer(XMLWriter $writer, array $issuer): void
    {
        $writer->startElement('Emisor');

        $writer->writeElement('RUTEmisor', Rut::parse($issuer['rut'])->formatBasic());
        $writer->writeElement('RznSoc', $issuer['legal_name']);
        $writer->writeElement('GiroEmis', $issuer['business_activity']);

        foreach ((array) $issuer['economic_activity'] as $acteco) {
            $writer->writeElement('Acteco', $acteco);
        }

        $this->optionalElements($writer, $issuer, $this->issuerFields());

        $writer->endElement(); // Emisor
    }

    /**
     * Return optional issuer field mappings.
     *
     * @return array<string, string>
     */
    protected function issuerFields(): array
    {
        return [
            'telephone' => 'Telefono',
            'address' => 'DirOrigen',
            'commune' => 'CmnaOrigen',
            'city' => 'CiudadOrigen',
            'branch' => 'CdgSIISucur',
        ];
    }

    /**
     * Append receiver information.
     *
     * @param  array<string, mixed>|null  $receiver
     */
    protected function appendReceiver(XMLWriter $writer, ?array $receiver): void
    {
        if ($receiver === null) {
            return;
        }

        $writer->startElement('Receptor');

        $writer->writeElement('RUTRecep', Rut::parse($receiver['rut'])->formatBasic());
        $writer->writeElement('RznSocRecep', $receiver['legal_name']);
        $this->optionalElements($writer, $receiver, $this->receiverFields());

        $writer->endElement(); // Receptor
    }

    /**
     * Return optional receiver field mappings.
     *
     * @return array<string, string>
     */
    protected function receiverFields(): array
    {
        return [
            'business_activity' => 'GiroRecep',
            'email' => 'CorreoRecep',
            'address' => 'DirRecep',
            'commune' => 'CmnaRecep',
            'city' => 'CiudadRecep',
        ];
    }

    /**
     * Append monetary totals.
     *
     * @param  array{net: int, exempt: int, tax: int, total: int}  $totals
     */
    protected function appendTotals(XMLWriter $writer, array $totals, array $taxes = []): void
    {
        $writer->startElement('Totales');

        $this->positiveElement($writer, 'MntNeto', $totals['net']);
        $this->positiveElement($writer, 'MntExe', $totals['exempt']);

        if ($totals['tax'] > 0) {
            $writer->writeElement('TasaIVA', '19');
            $writer->writeElement('IVA', (string) $totals['tax']);
        }

        foreach ($taxes as $taxCode => $amount) {
            $writer->startElement('ImptoReten');
            $writer->writeElement('TipoImp', (string) $taxCode);
            $writer->writeElement('MontoImp', (string) $amount);
            $writer->endElement();
        }

        $writer->writeElement('MntTotal', (string) $totals['total']);

        $writer->endElement(); // Totales
    }

    /**
     * Append all detail lines.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function appendItems(XMLWriter $writer, array $items): void
    {
        foreach ($items as $index => $item) {
            $writer->startElement('Detalle');

            $writer->writeElement('NroLinDet', (string) ($index + 1));
            $this->appendItemCode($writer, $item);
            $this->appendItemValues($writer, $item);

            $writer->endElement(); // Detalle
        }
    }

    /**
     * Append an optional item code.
     *
     * @param  array<string, mixed>  $item
     */
    protected function appendItemCode(XMLWriter $writer, array $item): void
    {
        if (empty($item['code'])) {
            return;
        }

        $writer->startElement('CdgItem');
        $writer->writeElement('TpoCodigo', $item['code_type'] ?? 'INT1');
        $writer->writeElement('VlrCodigo', $item['code']);
        $writer->endElement();
    }

    /**
     * Append item description and monetary values.
     *
     * @param  array<string, mixed>  $item
     */
    protected function appendItemValues(XMLWriter $writer, array $item): void
    {
        $writer->writeElement('NmbItem', $item['name']);
        $this->optionalElement($writer, 'DscItem', $item['description']);
        $writer->writeElement('QtyItem', $this->decimal($item['quantity']));
        $this->optionalElement($writer, 'UnmdItem', $item['unit']);
        $writer->writeElement('PrcItem', $this->decimal($item['unit_price']));
        $this->positiveElement($writer, 'DescuentoPct', (float) $item['discount_percentage']);
        $this->positiveElement($writer, 'IndExe', $item['exempt'] ? 1 : 0);

        if (!empty($item['taxes'])) {
            foreach ($item['taxes'] as $taxCode => $amount) {
                $writer->writeElement('CodImpAdic', (string) $taxCode);
            }
        }

        $writer->writeElement('MontoItem', (string) $this->itemTotal($item));
    }

    /**
     * Calculate one persisted detail line total.
     *
     * @param  array<string, mixed>  $item
     */
    protected function itemTotal(array $item): int
    {
        return round($item['unit_price'] * $item['quantity'] * (1 - ($item['discount_percentage'] / 100)));
    }

    /**
     * Append global discounts and surcharges.
     */
    protected function appendGlobalModifiers(XMLWriter $writer, array $modifiers): void
    {
        foreach ($modifiers as $index => $modifier) {
            $writer->startElement('DscRcgGlobal');

            $writer->writeElement('NroLinDR', (string) ($index + 1));
            $writer->writeElement('TpoMov', $modifier['type']); // 'D' or 'R'

            if (!empty($modifier['description'])) {
                $writer->writeElement('GlosaDR', substr($modifier['description'], 0, 45));
            }

            $writer->writeElement('TpoValor', $modifier['value_type']); // '%' or '$'
            $writer->writeElement('ValorDR', (string) round($modifier['value'], 4));

            if (isset($modifier['target']) && in_array($modifier['target'], [1, 2, 3])) {
                $writer->writeElement('IndExeDR', (string) $modifier['target']);
            }

            $writer->endElement(); // DscRcgGlobal
        }
    }

    /**
     * Append all document references.
     *
     * @param  list<array<string, mixed>>  $references
     */
    protected function appendReferences(XMLWriter $writer, array $references): void
    {
        foreach ($references as $index => $reference) {
            $writer->startElement('Referencia');

            $writer->writeElement('NroLinRef', (string) ($index + 1));
            $writer->writeElement('TpoDocRef', (string) $reference['document_type']);
            $writer->writeElement('FolioRef', (string) $reference['folio']);
            $writer->writeElement('FchRef', $reference['date']);
            $this->optionalElement($writer, 'CodRef', $reference['reference_code']);
            $this->optionalElement($writer, 'RazonRef', $reference['reason']);

            $writer->endElement();
        }
    }

    /**
     * Append mapped non-empty values.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $fields
     */
    protected function optionalElements(XMLWriter $writer, array $data, array $fields): void
    {
        foreach ($fields as $key => $name) {
            $this->optionalElement($writer, $name, $data[$key] ?? null);
        }
    }

    /**
     * Append a positive numeric element.
     */
    protected function positiveElement(XMLWriter $writer, string $name, int|float $value): void
    {
        if ($value > 0) {
            $writer->writeElement($name, (string) $value);
        }
    }

    /**
     * Append an element only when its value is present.
     */
    protected function optionalElement(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $writer->writeElement($name, (string) $value);
        }
    }

    /**
     * Format a decimal without insignificant trailing zeroes.
     */
    protected function decimal(int|float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
