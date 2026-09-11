<?php

namespace Laragear\Dte\Certification;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Enums\IecvType;
use Laragear\Dte\Enums\SiiTaxes;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use XMLWriter;
use function is_int;
use function round;
use function str_replace;
use function substr;

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

        $this->appendCaratula($writer, $dtes->first()->issuer_rut, $senderRut, $period, $resolutionDate, $resolutionNumber, $type);
        $this->appendResumenPeriodo($writer, $dtes, $this->parseOptions($properties));
        $this->appendDetalle($writer, $dtes, $type);

        $writer->writeElement('TmstFirma', $this->date->now('America/Santiago')->format('Y-m-d\TH:i:s'));

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
        $writer->writeAttribute('xmlns', XmlDomFactory::XML_NAMESPACE);
        $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $writer->writeAttribute('xsi:schemaLocation', XmlDomFactory::XML_NAMESPACE.' LibroCV_v10.xsd');
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
        Rut $emisorRut,
        Rut $senderRut,
        string $period,
        string $resolutionDate,
        int $resolutionNumber,
        IecvType $type,
    ): void {
        $writer->startElement('Caratula');
        $writer->writeElement('RutEmisorLibro', $emisorRut->formatBasic());
        $writer->writeElement('RutEnvia', $senderRut->formatBasic());
        $writer->writeElement('PeriodoTributario', $period);
        $writer->writeElement('FchResol', $resolutionDate);
        $writer->writeElement('NroResol', (string) $resolutionNumber);
        $writer->writeElement('TipoOperacion', $type->value);
        $writer->writeElement('TipoLibro', 'ESPECIAL');
        $writer->writeElement('TipoEnvio', 'TOTAL');
        $writer->writeElement('FolioNotificacion', IecvType::Purchases === $type ? '2' : '1');
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
        $commonIvaDtes = $dtesOfType->filter->iva_common_use;
        $regularDtes = $dtesOfType->reject->iva_common_use;

        $totalIvaAmount = $regularDtes->sum('amount_taxes');

        $writer->writeElement('TotMntIVA', (string) $totalIvaAmount);

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
            if (is_array($dte->taxes) && $dte->taxes !== []) {
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
            $writer->writeElement('TasaImp', $dte->amount_taxes > 0
            ? (string) number_format((float) config('dte.taxes.iva_rate', 19), 2)
            : '0.00');
            $writer->writeElement('FchDoc', $dte->issued_on->format('Y-m-d'));

            $this->appendDetalleRut($writer, $dte, $type);
            $this->appendDetalleMontos($writer, $dte);
            $this->appendDetalleImpuestos($writer, $dte);

            $writer->writeElement('MntTotal', (string) $dte->amount_total);

            $writer->endElement();
        }
    }

    /**
     * Append the corresponding RUT to the document detail based on the IECV type.
     */
    protected function appendDetalleRut(XMLWriter $writer, SiiDte $dte, IecvType $type): void
    {
        $rut = $type === IecvType::Sales
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

        if ($dte->iva_common_use && $dte->amount_taxes > 0) {
            $writer->writeElement('IVAUsoComun', (string) $dte->amount_taxes);
        }

        if (! $dte->iva_common_use) {
            $writer->writeElement('MntIVA', (string) $dte->amount_taxes);
        }
    }

    /**
     * Append additional taxes to the document detail.
     */
    protected function appendDetalleImpuestos(XMLWriter $writer, SiiDte $dte): void
    {
        foreach (Arr::wrap($dte->taxes) as $code => $taxAmount) {
            $writer->startElement('OtrosImp');
            $writer->writeElement('CodImp', (string) $code);
            $writer->writeElement('MntImp', (string) $taxAmount);
            $writer->endElement();
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Purchases Book (IECV)
     |--------------------------------------------------------------------------
     */

    /**
     * Build the EnvioLibro XML for a Purchases Book from test set entries.
     *
     * @param  array<int, IecvPurchaseData>  $entries
     * @param  array<int, IecvPropertyData>  $properties
     */
    public function buildPurchases(
        array $entries,
        string $period,
        string $resolutionDate,
        int $resolutionNumber,
        Rut $companyRut,
        Rut $senderRut,
        array $properties = [],
    ): string {
        $writer = $this->xml->writer();
        $writer->openMemory();
        $this->startDocument($writer, $period, IecvType::Purchases);

        $this->appendCaratula($writer, $companyRut, $senderRut, $period, $resolutionDate, $resolutionNumber, IecvType::Purchases);
        $this->appendResumenPeriodoForEntries($writer, $entries, $this->parseOptions($properties));
        $this->appendDetalleForEntries($writer, $entries);

        $writer->writeElement('TmstFirma', $this->date->now('America/Santiago')->format('Y-m-d\TH:i:s'));

        $this->endDocument($writer);

        return $writer->outputMemory();
    }

    /**
     * Compute taxes and total for a purchase entry.
     *
     * @return array{taxes: int, total: int}
     */
    protected function computeEntryAmounts(IecvPurchaseData $entry): array
    {
        $taxes = (int) round($entry->amountNet * SiiTaxes::ivaDecimal(), 0, PHP_ROUND_HALF_UP);
        $total = $entry->amountNet + $entry->amountExempt + $taxes;

        if ($entry->ivaRetainedTotal) {
            $total -= $taxes;
        }

        return ['taxes' => $taxes, 'total' => $total];
    }

    /**
     * Append ResumenPeriodo from purchase entries grouped by document type.
     *
     * @param  array<int, IecvPurchaseData>  $entries
     * @param  array<string, mixed>  $options
     */
    protected function appendResumenPeriodoForEntries(XMLWriter $writer, array $entries, array $options): void
    {
        $writer->startElement('ResumenPeriodo');

        $grouped = [];
        foreach ($entries as $entry) {
            $typeKey = $entry->documentType instanceof \BackedEnum
                ? $entry->documentType->value
                : $entry->documentType;

            if (!isset($grouped[$typeKey])) {
                $grouped[$typeKey] = [
                    'type' => $typeKey,
                    'count' => 0,
                    'exempt' => 0,
                    'net' => 0,
                    'taxes' => 0,
                    'iva_common_use' => false,
                    'total' => 0,
                ];
            }

            $amounts = $this->computeEntryAmounts($entry);
            $grouped[$typeKey]['count']++;
            $grouped[$typeKey]['exempt'] += $entry->amountExempt;
            $grouped[$typeKey]['net'] += $entry->amountNet;
            $grouped[$typeKey]['taxes'] += $amounts['taxes'];
            $grouped[$typeKey]['total'] += $amounts['total'];
            $grouped[$typeKey]['iva_common_use'] = $grouped[$typeKey]['iva_common_use'] || $entry->ivaCommonUse;
        }

        foreach ($grouped as $group) {
            $writer->startElement('TotalesPeriodo');
            $writer->writeElement('TpoDoc', (string) $group['type']);
            $writer->writeElement('TotDoc', (string) $group['count']);
            $writer->writeElement('TotMntExe', (string) $group['exempt']);
            $writer->writeElement('TotMntNeto', (string) $group['net']);
            $writer->writeElement('TotMntIVA', (string) $group['taxes']);

            if ($group['iva_common_use']) {
                $writer->writeElement('TotOpIVAUsoComun', (string) $group['count']);
                $writer->writeElement('TotIVAUsoComun', (string) $group['taxes']);

                if (isset($options['FctProp'])) {
                    $factor = (float) $options['FctProp'];
                    $writer->writeElement('FctProp', (string) round($factor, 3));
                    $writer->writeElement('TotCredIVAUsoComun', (string) round($group['taxes'] * $factor));
                }
            }

            $writer->writeElement('TotMntTotal', (string) $group['total']);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * Append Detalle from purchase entries in XSD element order.
     *
     * @param  array<int, IecvPurchaseData>  $entries
     */
    protected function appendDetalleForEntries(XMLWriter $writer, array $entries): void
    {
        foreach ($entries as $entry) {
            $amounts = $this->computeEntryAmounts($entry);
            $rate = SiiTaxes::ivaRate();
            $rateStr = number_format((float) $rate, 2);

            $writer->startElement('Detalle');

            $docType = $entry->documentType instanceof \BackedEnum
                ? $entry->documentType->value
                : $entry->documentType;
            $writer->writeElement('TpoDoc', (string) $docType);
            $writer->writeElement('NroDoc', (string) $entry->folio);
            $writer->writeElement('TasaImp', $entry->amountNet > 0 ? $rateStr : '0.00');

            if ($entry->noCost) {
                $writer->writeElement('IndSinCosto', '1');
            }

            $writer->writeElement('FchDoc', $entry->issuedOn);

            $issuerRut = $entry->issuerRut instanceof Rut
                ? $entry->issuerRut->formatBasic()
                : Rut::parse($entry->issuerRut)->formatBasic();
            $writer->writeElement('RUTDoc', $issuerRut);

            if ($entry->referenceType !== null) {
                $refType = $entry->referenceType instanceof \BackedEnum
                    ? $entry->referenceType->value
                    : $entry->referenceType;
                $writer->writeElement('TpoDocRef', (string) $refType);
            }
            if ($entry->referenceFolio !== null) {
                $writer->writeElement('FolioRef', (string) $entry->referenceFolio);
            }

            if ($entry->amountExempt > 0) {
                $writer->writeElement('MntExe', (string) $entry->amountExempt);
            }
            if ($entry->amountNet > 0) {
                $writer->writeElement('MntNeto', (string) $entry->amountNet);
            }

            if ($entry->ivaCommonUse && $amounts['taxes'] > 0) {
                $writer->writeElement('IVAUsoComun', (string) $amounts['taxes']);
            } else {
                $writer->writeElement('MntIVA', (string) $amounts['taxes']);
            }

            if ($entry->ivaRetainedTotal) {
                $writer->writeElement('IVARetTotal', (string) $amounts['taxes']);
            }

            $writer->writeElement('MntTotal', (string) $amounts['total']);

            $writer->endElement();
        }
    }
}
