<?php

namespace Laragear\Dte\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Builders\CommercialReceiptBuilder;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Events\InboundDteAcknowledged;
use Laragear\Dte\Events\InboundDteAnswered;
use Laragear\Dte\Gateways\ReclamoWebserviceGateway;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Rut\Rut;
use LogicException;

class DteClaimService
{
    /**
     * Create a new Dte Claim Service instance.
     */
    public function __construct(
        protected Dispatcher $event,
        protected DateFactory $date,
        protected ReclamoWebserviceGateway $gateway,
        protected CommercialReceiptBuilder $builder,
    ) {
        //
    }

    /**
     * Commercially accept a vendor invoice.
     */
    public function accept(
        SiiInboundDocument $document,
        Rut $signer,
        string $location,
        DigitalCertificate $certificate,
        ?DateTimeImmutable $signedAt = null
    ): string {
        $this->transitionClaim(
            $document,
            fn(SiiInboundDocument $d) => $this->gateway->accept($d),
            InboundDteStatus::CommercialAccepted,
            'ACD'
        );

        $receiptXml = $this->builder->build($document, $signer, $location, $certificate, $signedAt);
        $this->event->dispatch(new InboundDteAcknowledged($document, $receiptXml));

        return $receiptXml;
    }

    /**
     * Reject a vendor invoice commercially (Reclamo al Contenido).
     */
    public function reject(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->transitionClaim(
            $document,
            fn(SiiInboundDocument $d) => $this->gateway->reject($d, $reason),
            InboundDteStatus::CommercialRejected,
            'RCD'
        );
    }

    /**
     * Reject a vendor invoice due to missing goods (Reclamo Falta de Mercaderías).
     */
    public function rejectGoods(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->transitionClaim(
            $document,
            fn(SiiInboundDocument $d) => $this->gateway->rejectGoods($d, $reason),
            InboundDteStatus::CommercialRejected,
            'RFT'
        );
    }

    /**
     * Transition a document's claim status after a gateway operation.
     */
    protected function transitionClaim(
        SiiInboundDocument $document,
        callable $gatewayCall,
        InboundDteStatus $newStatus,
        string $claimCode
    ): void {
        $this->ensureNotClaimed($document);

        $gatewayCall($document);

        $document->forceFill([
            'status' => $newStatus,
            'claimed_at' => $this->date->now(),
            'claim_status' => $claimCode,
        ])->save();

        $this->event->dispatch(new InboundDteAnswered($document));
    }

    /**
     * Ensure the document has not already been commercially accepted or rejected.
     */
    protected function ensureNotClaimed(SiiInboundDocument $document): void
    {
        if (in_array($document->status, [
            InboundDteStatus::CommercialAccepted,
            InboundDteStatus::CommercialRejected,
        ], true)) {
            throw new LogicException('The document has already been commercially claimed or accepted.');
        }
    }
}
