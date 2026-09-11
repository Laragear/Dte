<?php

namespace Laragear\Dte\Actions\CreateEnvelope\Pipes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\SiiRut;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use LogicException;
use RuntimeException;
use UnexpectedValueException;
use XMLWriter;
use function is_int;

class BuildCaratulaHeader
{
    /**
     * Create a Build Caratula Header pipe instance.
     */
    public function __construct(
        protected Repository $config,
        protected XmlDomFactory $xml,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Hande the incoming DTE Envelope Assembly.
     *
     * @param  Closure(Assembly): Assembly  $next
     */
    public function handle(Assembly $assembly, Closure $next): Assembly
    {
        $this->validateDocuments($assembly);

        $writer = $this->writer($assembly->requirePath());

        $this->openEnvelope($writer, $assembly);
        $this->writeCaratula($assembly, $writer);

        $assembly->writer = $writer;

        return $next($assembly);
    }

    /**
     * Ensure the envelope contains a homogeneous signed document batch.
     */
    protected function validateDocuments(Assembly $assembly): void
    {
        $assembly->expectedDocuments = $assembly->envelope->dtes()
            ->when($assembly->targetReceiverRut, static function (EloquentBuilder $query, Rut $rut): void {
                $query->where('receiver_num', $rut->num);
            })
            ->count();

        if ($assembly->expectedDocuments < 1) {
            throw new LogicException('The DTE envelope must contain at least one signed document.');
        }

        if ($assembly->expectedDocuments > $this->maximumDocuments($assembly)) {
            throw new LogicException('The DTE envelope exceeds the configured document limit.');
        }

        if ($this->hasInvalidDocuments($assembly)) {
            throw new LogicException('The DTE envelope documents must share its issuer, signed state, and receipt type.');
        }
    }

    /**
     * Determine whether an attached DTE violates envelope boundaries.
     */
    protected function hasInvalidDocuments(Assembly $assembly): bool
    {
        $envelope = $assembly->envelope;

        return $envelope
            ->dtes()
            ->when($assembly->targetReceiverRut, function (EloquentBuilder $query, Rut $rut) {
                return $query->where('receiver_num', $rut->num);
            })
            ->where(static function (EloquentBuilder $query) use ($envelope): void {
                $query->where(static function (EloquentBuilder $q) use ($envelope): void {
                    $q->where('issuer_num', '!=', $envelope->issuer_rut->num)
                        ->orWhere('issuer_vd', '!=', $envelope->issuer_rut->vd)
                        ->orWhere('status', '!=', DteStatus::Signed->value);
                });

                if ($envelope->isReceipt()) {
                    $query->orWhereNotIn('document_type', [
                        DteType::Receipt->value,
                        DteType::ExemptReceipt->value,
                    ]);
                } else {
                    $query->orWhereIn('document_type', [
                        DteType::Receipt->value,
                        DteType::ExemptReceipt->value,
                    ]);
                }
            })
            ->exists();
    }

    /**
     * Return the configured envelope document limit.
     */
    protected function maximumDocuments(Assembly $assembly): int
    {
        $configKey = $assembly->envelope->isReceipt()
            ? 'dte.envelopes.max.receipts'
            : 'dte.envelopes.max.documents';

        $maximum = $this->config->get($configKey);

        if (!is_int($maximum) || $maximum < 1) {
            throw new UnexpectedValueException('The envelope document limit must be a positive integer.');
        }

        return $maximum;
    }

    /**
     * Create a file-backed XML writer.
     */
    protected function writer(string $path): XMLWriter
    {
        $writer = $this->xml->writer();

        if (!$writer->openUri($path)) {
            throw new RuntimeException('Unable to open the temporary envelope XML file.');
        }

        return $writer;
    }

    /**
     * Write the EnvioDTE and SetDTE opening elements.
     */
    protected function openEnvelope(XMLWriter $writer, Assembly $assembly): void
    {
        $tag = $assembly->envelope->type === 'boleta' ? 'EnvioBOLETA' : 'EnvioDTE';

        $writer->startDocument('1.0', 'ISO-8859-1');
        $writer->startElementNs(null, $tag, XmlDomFactory::XML_NAMESPACE);

        $writer->writeAttributeNs(
            'xsi',
            'schemaLocation',
            'http://www.w3.org/2001/XMLSchema-instance',
            XmlDomFactory::XML_NAMESPACE . ' EnvioDTE_v10.xsd'
        );
        $writer->writeAttribute('version', '1.0');

        $writer->startElement('SetDTE');
        $writer->writeAttribute('ID', 'SetDoc');
    }

    /**
     * Write the required Caratula values.
     */
    protected function writeCaratula(Assembly $assembly, XMLWriter $writer): void
    {
        $envelope = $assembly->envelope;

        $writer->startElement('Caratula');
        $writer->writeAttribute('version', '1.0');
        $writer->writeElement('RutEmisor', $envelope->issuer_rut->formatBasic());
        $writer->writeElement('RutEnvia', $envelope->sender_rut->formatBasic());
        $writer->writeElement(
            'RutReceptor', $assembly->targetReceiverRut?->formatBasic() ?? SiiRut::Sii->formatBasic()
        );
        $writer->writeElement('FchResol', $this->resolutionDate($assembly));
        $writer->writeElement('NroResol', (string) $this->resolutionNumber($assembly));
        $writer->writeElement('TmstFirmaEnv', $this->date->now('America/Santiago')->format('Y-m-d\TH:i:s'));

        $this->writeSubtotal($assembly, $writer);

        $writer->endElement();
        $writer->flush();
    }

    /**
     * Write a SubTotDTE for each document type present in the envelope.
     */
    protected function writeSubtotal(Assembly $assembly, XMLWriter $writer): void
    {
        $counts = $assembly->envelope
            ->dtes()
            ->when($assembly->targetReceiverRut, static function (EloquentBuilder $query, Rut $rut): void {
                $query->where('receiver_num', $rut->num);
            })
            ->select('document_type')
            ->selectRaw('count(*) as total')
            ->groupBy('document_type')
            ->orderBy('document_type')
            ->get();

        foreach ($counts as $row) {
            $writer->startElement('SubTotDTE');
            $writer->writeElement('TpoDTE', (string) $row->document_type->value);
            $writer->writeElement('NroDTE', (string) $row->total);
            $writer->endElement();
        }
    }

    /**
     * Return the configured strict issuer resolution date.
     */
    protected function resolutionDate(Assembly $assembly): string
    {
        $date = $assembly->envelope->resolution_date;

        if (!$date) {
            throw new UnexpectedValueException('The issuer resolution date must use YYYY-MM-DD format.');
        }

        return $date->format('Y-m-d');
    }

    /**
     * Return the configured issuer resolution number.
     */
    protected function resolutionNumber(Assembly $assembly): int
    {
        $value = $assembly->envelope->resolution_number;

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw new UnexpectedValueException('The issuer resolution number must be a non-negative integer.');
    }
}
