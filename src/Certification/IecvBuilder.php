<?php

namespace Laragear\Dte\Certification;

use Illuminate\Support\Collection;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use XMLWriter;

use function round;
use function str_replace;

class IecvBuilder
{
    /**
     * Create a new IECV Builder instance.
     */
    public function __construct(
        protected DateFactory $date,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Build the EnvioLibro XML content.
     *
     * @param  Collection<int, SiiDte>  $dtes
     * @param  array<int, IecvPropertyData>  $properties
     */
    public function build(
        Collection $dtes,
        IecvType $type,
        string $period,
        string $resolutionDate,
        int $resolutionNumber,
        Rut $senderRut,
        array $properties = [],
    ): string {
        $writer = $this->xml->writer();
        $writer->openMemory();
        $this->startDocument($writer, $period, $type);

        $this->appendCaratula($writer, $dtes->first(), $senderRut, $period, $resolutionDate, $resolutionNumber, $type);
        $this->appendResumenPeriodo($writer, $dtes, $this->parseOptions($properties));
        $this->appendDetalle($writer, $dtes, $type);

        $writer->writeElement('TmstFirma', $this->date->now()->format('Y-m-d\TH:i:s'));

        $this->endDocument($writer);

        return $writer->outputMemory();
    }

    /**
     * Parse the given IECV properties into an options array.
     *
     * @param  array<int, IecvPropertyData>  $properties
     * @return array<string, mixed>
     */
    protected function parseOptions(array $properties): array
    {
        $options = [];

        foreach ($properties as $property) {
            if ($property instanceof IecvPropertyData) {
                $options[$property->property->value] = $property->value;
            }
        }

        return $options;
    }

    /**
     * Initialize the XML document and its root elements.
     */
    protected function startDocument(XMLWriter $writer, string $period, IecvType $type): void
    {
        $writer->startDocument('1.0', 'ISO-8859-1');
        $writer->startElement('LibroCompraVenta');
        $writer->writeAttribute('xmlns', 'http://www.sii.cl/SiiDte');
        $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $writer->writeAttribute('xsi:schemaLocation', 'http://www.sii.cl/SiiDte LibroCV_v10.xsd');
        $writer->writeAttribute('version', '1.0');

        $writer->startElement('EnvioLibro');
        $writer->writeAttribute('ID', 'Libro'.$type->value.'_'.str_replace('-', '', $period));
    }

    /**
     * Close the XML document root elements.
     */
    protected function endDocument(XMLWriter $writer): void
    {
        $writer->endElement(); // EnvioLibro
        $writer->endElement(); // LibroCompraVenta
        $writer->endDocument();
    }

    /**
     * Append the Caratula (cover) section of the IECV.
     */
    protected function appendCaratula(
        XMLWriter $writer,
        SiiDte $firstDte,
        Rut $senderRut,
        string $period,
        string $resolutionDate,
        int $resolutionNumber,
        IecvType $type,
    ): void {
        $writer->startElement('Caratula');
        $writer->writeElement('RutEmisorLibro', $firstDte->issuer_rut->formatBasic());
        $writer->writeElement('RutEnvia', $senderRut->formatBasic());
        $writer->writeElement('PeriodoTributario', $period);
        $writer->writeElement('FchResol', $resolutionDate);
        $writer->writeElement('NroResol', (string) $resolutionNumber);
        $writer->writeElement('TipoOperacion', $type->value);
        $writer->writeElement('TipoLibro', 'ESPECIAL');
        $writer->writeElement('TipoEnvio', 'TOTAL');
        $writer->writeElement('FolioNotificacion', '1');
        $writer->endElement();
    }

    /**
     * Append the Resumen Periodo section, grouping DTEs by document type.
     *
     * @param  Collection<int, SiiDte>  $dtes
     * @param  array<string, mixed>  $options
     */
    protected function appendResumenPeriodo(XMLWriter $writer, Collection $dtes, array $options): void
    {
        $writer->startElement('ResumenPeriodo');
        foreach ($dtes->groupBy('document_type') as $documentType => $dtesOfType) {
            $writer->startElement('TotalesPeriodo');
            $writer->writeElement('TpoDoc', (string) $documentType);
            $writer->writeElement('TotDoc', (string) $dtesOfType->count());
            $writer->writeElement('TotMntExe', (string) $dtesOfType->sum('amount_exempt'));
            $writer->writeElement('TotMntNeto', (string) $dtesOfType->sum('amount_net'));

            $this->appendResumenIva($writer, $dtesOfType, $options);
            $this->appendResumenOtrosImpuestos($writer, $dtesOfType);

            $writer->writeElement('TotMntTotal', (string) $dtesOfType->sum('amount_total'));
            $writer->endElement();
        }
        $writer->endElement();
    }

    /**
     * Append the IVA summary for the given grouped DTEs.
     *
     * @param  Collection<int, SiiDte>  $dtesOfType
     * @param  array<string, mixed>  $options
     */
    protected function appendResumenIva(XMLWriter $writer, Collection $dtesOfType, array $options): void
    {
        $commonIvaDtes = $dtesOfType->filter(fn ($dte) => ! empty($dte->iva_uso_comun));
        $regularDtes = $dtesOfType->reject(fn ($dte) => ! empty($dte->iva_uso_comun));
        $totalIvaAmount = $regularDtes->sum('amount_taxes');

        if ($totalIvaAmount > 0) {
            $writer->writeElement('TotMntIVA', (string) $totalIvaAmount);
        }

        if ($commonIvaDtes->isNotEmpty()) {
            $this->appendResumenIvaUsoComun($writer, $commonIvaDtes, $options);
        }
    }

    /**
     * Append the Common Use IVA summary.
     *
     * @param  Collection<int, SiiDte>  $commonIvaDtes
     * @param  array<string, mixed>  $options
     */
    protected function appendResumenIvaUsoComun(XMLWriter $writer, Collection $commonIvaDtes, array $options): void
    {
        $writer->writeElement('TotOpIVAUsoComun', (string) $commonIvaDtes->count());
        $totalCommonIva = $commonIvaDtes->sum('amount_taxes');
        $writer->writeElement('TotIVAUsoComun', (string) $totalCommonIva);

        if (isset($options['FctProp'])) {
            $writer->writeElement('FctProp', (string) round($options['FctProp'], 3));
            $writer->writeElement('TotCredIVAUsoComun', (string) round($totalCommonIva * (float) $options['FctProp']));
        }
    }

    /**
     * Append the other taxes summary for the grouped DTEs.
     *
     * @param  Collection<int, SiiDte>  $dtesOfType
     */
    protected function appendResumenOtrosImpuestos(XMLWriter $writer, Collection $dtesOfType): void
    {
        $otherTaxes = [];
        foreach ($dtesOfType as $dte) {
            if (! empty($dte->taxes) && is_array($dte->taxes)) {
                foreach ($dte->taxes as $code => $taxAmount) {
                    $otherTaxes[$code] = ($otherTaxes[$code] ?? 0) + $taxAmount;
                }
            }
        }

        foreach ($otherTaxes as $code => $taxAmount) {
            $writer->startElement('TotOtrosImp');
            $writer->writeElement('CodImp', (string) $code);
            $writer->writeElement('TotMntImp', (string) $taxAmount);
            $writer->endElement();
        }
    }

    /**
     * Append the detail section containing each individual document.
     *
     * @param  Collection<int, SiiDte>  $dtes
     */
    protected function appendDetalle(XMLWriter $writer, Collection $dtes, IecvType $type): void
    {
        foreach ($dtes as $dte) {
            $writer->startElement('Detalle');
            $writer->writeElement('TpoDoc', (string) $dte->document_type->value);
            $writer->writeElement('NroDoc', (string) $dte->folio);
            $writer->writeElement('TasaImp', '19.00');
            $writer->writeElement('FchDoc', $dte->issued_on->format('Y-m-d'));

            $this->appendDetalleRut($writer, $dte, $type);
            $this->appendDetalleMontos($writer, $dte);
            $this->appendDetalleImpuestos($writer, $dte);

            $writer->endElement();
        }
    }

    /**
     * Append the corresponding RUT to the document detail based on the IECV type.
     */
    protected function appendDetalleRut(XMLWriter $writer, SiiDte $dte, IecvType $type): void
    {
        $rut = $type === IecvType::Ventas
            ? $dte->receiver_rut->formatBasic()
            : $dte->issuer_rut->formatBasic();

        $writer->writeElement('RUTDoc', $rut);
    }

    /**
     * Append the amounts to the document detail.
     */
    protected function appendDetalleMontos(XMLWriter $writer, SiiDte $dte): void
    {
        if ($dte->amount_exempt > 0) {
            $writer->writeElement('MntExe', (string) $dte->amount_exempt);
        }

        if ($dte->amount_net > 0) {
            $writer->writeElement('MntNeto', (string) $dte->amount_net);
        }

        if ($dte->iva_uso_comun && $dte->amount_taxes > 0) {
            $writer->writeElement('IVAUsoComun', (string) $dte->amount_taxes);
        } elseif ($dte->amount_taxes > 0) {
            $writer->writeElement('MntIVA', (string) $dte->amount_taxes);
        }

        $writer->writeElement('MntTotal', (string) $dte->amount_total);
    }

    /**
     * Append additional taxes to the document detail.
     */
    protected function appendDetalleImpuestos(XMLWriter $writer, SiiDte $dte): void
    {
        if (! empty($dte->taxes) && is_array($dte->taxes)) {
            foreach ($dte->taxes as $code => $taxAmount) {
                $writer->startElement('OtrosImp');
                $writer->writeElement('CodImp', (string) $code);
                $writer->writeElement('MntImp', (string) $taxAmount);
                $writer->endElement();
            }
        }
    }
}
