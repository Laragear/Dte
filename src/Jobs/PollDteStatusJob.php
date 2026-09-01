<?php

namespace Laragear\Dte\Jobs;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Str;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Enums\DteType as Type;
use Laragear\Dte\Events\DteAccepted;
use Laragear\Dte\Events\DteRejected;
use Laragear\Dte\Gateways\BoletaRestGateway;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\XmlDomFactory;
use Psr\Log\LoggerInterface;

class PollDteStatusJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SiiDte $dte,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        LoggerInterface $log,
        Dispatcher $event,
        SoapGateway $gateway,
        BoletaRestGateway $boletaGateway,
        XmlDomFactory $xmlDomFactory,
        DateFactory $date,
    ): void {
        if ($this->dte->status->isTerminalState()) {
            return;
        }

        try {
            if ($this->dte->document_type === Type::Receipt || $this->dte->document_type === Type::ExemptReceipt) {
                $status = $boletaGateway->documentStatus($this->dte);
                $this->processBoletaDteStatus($status, $event, $log, $date);
            } else {
                $this->processDteStatus($this->queryDteStatus($gateway), $event, $log, $xmlDomFactory, $date);
            }
        } catch (Exception $e) {
            $log->error("Failed to poll DTE status for ID {$this->dte->getKey()}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Queries the SII for the current status of the DTE.
     */
    protected function queryDteStatus(SoapGateway $gateway): string
    {
        $issuer = $this->dte->issuer_rut;
        $token = $gateway->token($issuer);

        $response = $gateway->query($issuer, 'QueryEstDte', 'getEstDte', [
            'RutConsultante' => $issuer->num,
            'DvConsultante' => $issuer->vd,
            'RutCompania' => $issuer->num,
            'DvCompania' => $issuer->vd,
            'RutReceptor' => $this->dte->receiver_rut->num,
            'DvReceptor' => $this->dte->receiver_rut->vd,
            'TipoDte' => $this->dte->document_type->value,
            'FolioDte' => $this->dte->folio,
            'FechaEmisionDte' => $this->dte->issued_on->format('dmY'),
            'MontoDte' => $this->dte->amount_total,
            'Token' => $token->value,
        ]);

        return is_object($response) ? $response->getEstDteResult ?? '' : (string) $response;
    }

    /**
     * Processes the SII DTE status response.
     */
    protected function processDteStatus(
        string $xml,
        Dispatcher $event,
        LoggerInterface $log,
        XmlDomFactory $factory,
        DateFactory $date,
    ): void {
        $simple = $factory->simpleXml($xml);
        $friendly = json_decode(json_encode($simple), true);
        $this->saveDteRepairs($friendly, $xml);

        // According to manual DOK = Accepted
        if (Str::contains($xml, '<ESTADO>DOK</ESTADO>')) {
            $this->dte->status = DteStatus::Accepted;
            $this->dte->accepted_at = $date->now();
            $this->dte->save();

            $event->dispatch(new DteAccepted($this->dte));
        } elseif ($this->isRejectedStatus($xml)) {
            $this->dte->status = DteStatus::Rejected;
            $this->dte->rejected_at = $date->now();
            $this->dte->save();

            $event->dispatch(new DteRejected($this->dte));
        } else {
            // Processing or something else, DTE might be waiting
            $log->warning("Unknown or non-terminal SII DTE status received for DTE {$this->dte->getKey()}: ".$xml);
        }
    }

    /**
     * Processes the SII boleta status response.
     */
    protected function processBoletaDteStatus(
        array $status,
        Dispatcher $event,
        LoggerInterface $log,
        DateFactory $date,
    ): void {
        $estado = $status['estado'] ?? null;

        $this->saveDteRepairs($status, json_encode($status));

        if ($estado === 'DOK') {
            $this->dte->status = DteStatus::Accepted;
            $this->dte->accepted_at = $date->now();
            $this->dte->save();

            $event->dispatch(new DteAccepted($this->dte));
        } elseif (in_array($estado,
            ['RCH', 'DNK', 'FAU', 'FNA', 'FAN', 'EMP', 'TMD', 'TMC', 'MMD', 'MMC', 'AND', 'ANC'], true)) {
            $this->dte->status = DteStatus::Rejected;
            $this->dte->rejected_at = $date->now();
            $this->dte->save();

            $event->dispatch(new DteRejected($this->dte));
        } else {
            $log->warning("Unknown SII boleta DTE status received for DTE {$this->dte->getKey()}: ".json_encode($status));
        }
    }

    /**
     * Checks if the XML response contains a rejected status code for the DTE.
     */
    protected function isRejectedStatus(string $xml): bool
    {
        return Str::contains($xml, [
            '<ESTADO>DNK</ESTADO>',
            '<ESTADO>FAU</ESTADO>',
            '<ESTADO>FNA</ESTADO>',
            '<ESTADO>FAN</ESTADO>',
            '<ESTADO>EMP</ESTADO>',
            '<ESTADO>TMD</ESTADO>',
            '<ESTADO>TMC</ESTADO>',
            '<ESTADO>MMD</ESTADO>',
            '<ESTADO>MMC</ESTADO>',
            '<ESTADO>AND</ESTADO>',
            '<ESTADO>ANC</ESTADO>',
            // and error states
            '<ERR_CODE>',
            '<ESTADO>-',
        ]);
    }

    /**
     * Saves the SII repairs and the raw response to the DTE and its payload.
     */
    protected function saveDteRepairs(array $friendly, string $raw): void
    {
        $this->dte->repairs = $friendly;

        if ($this->dte->relationLoaded('payload') || $this->dte->payload) {
            $this->dte->payload->update(['sii_response' => $raw]);
        }
    }
}
