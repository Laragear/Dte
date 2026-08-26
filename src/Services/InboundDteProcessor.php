<?php

namespace Laragear\Dte\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Laragear\Dte\Builders\XmlResponseBuilder;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Contracts\TenantResolverInterface;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Events\InboundDteReceived;
use Laragear\Dte\Events\InboundForgedDteReceived;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlValidator;
use Laragear\Rut\Rut;
use RuntimeException;
use SimpleXMLElement;

class InboundDteProcessor
{
    /**
     * Create a new Inbound DTE Processor instance.
     */
    public function __construct(
        protected Dispatcher $event,
        protected XmlValidator $validator,
        protected XmlDomFactory $xml,
        protected TenantResolverInterface $tenantResolver,
        protected SoapGateway $soapGateway,
        protected XmlResponseBuilder $responseBuilder,
        protected CertificateResolver $certificates,
        protected DteAuthenticityVerifier $authenticityVerifier,
    ) {
        //
    }

    /**
     * Process an inbound DTE email payload.
     *
     * @context Transaction
     */
    public function process(InboundEmailData $email): void
    {
        // 1. Structural and Signature Validation
        $this->validator->validate($email->xmlAttachment);

        // 2. Parse as SimpleXML for easier data extraction
        $simpleXml = $this->xml->simpleXml($email->xmlAttachment);

        $rootName = $simpleXml->getName();

        SiiInterchangeLog::query()
            ->getConnection()
            ->transaction(function () use ($rootName, $email, $simpleXml): void {
                $log = $this->createInterchangeLog($email);

                if ($rootName === 'EnvioDTE') {
                    $this->processEnvioDte($simpleXml, $log);
                } elseif ($rootName === 'RespuestaDTE') {
                    $this->processRespuestaDte($simpleXml, $log);
                } else {
                    throw new RuntimeException("Unsupported root XML element for inbound processing: {$rootName}");
                }
            });
    }

    /**
     * Create the audit log for the inbound interchange event.
     */
    protected function createInterchangeLog(InboundEmailData $email): SiiInterchangeLog
    {
        return SiiInterchangeLog::create([
            'message_id' => $email->messageId,
            'direction' => 'in',
            'type' => 'email',
            'sender' => $email->sender,
            'recipient' => 'resolved-from-xml', // Replaced during processing if needed
            'subject' => $email->subject,
            'processed_at' => now(),
        ]);
    }

    /**
     * Process a vendor EnvioDTE payload.
     */
    protected function processEnvioDte(SimpleXMLElement $xml, SiiInterchangeLog $log): void
    {
        if (! isset($xml->SetDTE->Caratula->RutReceptor)) {
            throw new RuntimeException('Missing RutReceptor in EnvioDTE.');
        }

        $rutReceptorStr = (string) $xml->SetDTE->Caratula->RutReceptor;
        $receiverRut = Rut::parse($rutReceptorStr);
        $tenant = $this->tenantResolver->resolve($receiverRut);

        if ($tenant === null) {
            throw new RuntimeException("Tenant for RUT {$receiverRut->formatBasic()} not found.");
        }

        $log->update(['recipient' => $receiverRut->formatBasic()]);

        foreach ($xml->SetDTE->DTE as $dteNode) {
            $this->processSingleDte($dteNode, $receiverRut, $log, $tenant);
        }
    }

    /**
     * Process an individual DTE from an EnvioDTE envelope.
     */
    protected function processSingleDte(
        SimpleXMLElement $dteNode,
        Rut $receiverRut,
        SiiInterchangeLog $log,
        object $tenant,
    ): void {
        $encabezado = $dteNode->Documento->Encabezado;
        $issuerRut = Rut::parse((string) $encabezado->Emisor->RUTEmisor);
        $documentType = DteType::from((int) $encabezado->IdDoc->TipoDTE);
        $folio = (int) $encabezado->IdDoc->Folio;
        $issuedOn = Carbon::parse((string) $encabezado->IdDoc->FchEmis);
        $amountTotal = (int) $encabezado->Totales->MntTotal;

        // Verify authenticity with SII QueryEstDteAv
        $isAuthentic = $this->verifyAuthenticity(
            $issuerRut,
            $receiverRut,
            $documentType,
            $folio,
            $issuedOn,
            $amountTotal,
        );

        $status = $isAuthentic ? InboundDteStatus::Received : InboundDteStatus::Forged;

        $inboundDocument = SiiInboundDocument::forceCreate([
            'sii_interchange_log_id' => $log->id,
            'issuer_rut' => $issuerRut->formatRaw(),
            'receiver_rut' => $receiverRut->formatRaw(),
            'document_type' => $documentType,
            'folio' => $folio,
            'issued_on' => $issuedOn,
            'amount_total' => $amountTotal,
            'status' => $status,
            'received_at' => now(),
            'validated_at' => now(), // Technically validated by signature + SII WS
        ]);

        $inboundDocument
            ->payload()
            ->create([
                'xml' => $dteNode->asXML(),
            ]);

        if ($isAuthentic) {
            $this->event->dispatch(new InboundDteReceived($inboundDocument, $tenant));
        } else {
            $this->event->dispatch(new InboundForgedDteReceived($inboundDocument, $tenant));
        }
    }

    /**
     * Verifies the authenticity of a DTE using the information given.
     */
    protected function verifyAuthenticity(
        Rut $issuer,
        Rut $receiver,
        DteType $type,
        int $folio,
        Carbon $issuedOn,
        int $amountTotal,
    ): bool {
        return $this->authenticityVerifier->verify(
            $issuer,
            $receiver,
            $type,
            $folio,
            $issuedOn,
            $amountTotal,
        );
    }

    /**
     * Process an inbound RespuestaDTE (Formato IC or Ley 19.983).
     */
    protected function processRespuestaDte(SimpleXMLElement $xml, SiiInterchangeLog $log): void
    {
        Rut::parse((string) $xml->Resultado->Caratula->RutResponde)->validate();

        $log->update(['recipient' => (string) $xml->Resultado->Caratula->RutRecibe]);

        foreach ($xml->Resultado->ResultadoDTE as $resultadoDte) {
            $tipoDte = DteType::from((int) $resultadoDte->TipoDTE);
            $folio = (int) $resultadoDte->Folio;
            $estadoDte = (string) $resultadoDte->EstadoDTE;

            // Find the original DTE we emitted
            $siiDte = SiiDte::query()
                ->where('issuer_num', Rut::parse((string) $xml->Resultado->Caratula->RutRecibe)->num)
                ->where('document_type', $tipoDte)
                ->where('folio', $folio)
                ->first();

            if ($siiDte) {
                // Determine timestamps based on EstadoDTE codes.
                // Normally: 0 = Aceptado, 1 = Rechazado (Formato IC)
                // Commercial: ACD = Aceptado, RCD = Rechazo, etc.
                if ($estadoDte === '0' || $estadoDte === 'ACD') {
                    $siiDte->accepted_at ??= now();
                } elseif ($estadoDte === '1' || $estadoDte === 'RCD') {
                    $siiDte->rejected_at ??= now();
                }

                $siiDte->touch('acknowledged_at');
            }
        }
    }
}
