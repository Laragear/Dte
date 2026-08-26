<?php

namespace Laragear\Dte\Services;

use Illuminate\Support\Carbon;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Rut\Rut;
use Throwable;

class DteAuthenticityVerifier
{
    /**
     * Create a new DTE Authenticity Verifier instance.
     */
    public function __construct(
        protected SoapGateway $soapGateway,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Query the SII WS to verify if the DTE exists in their records.
     * Returns false if the WS is unreachable (treated as pending verification).
     */
    public function verify(
        Rut $issuer,
        Rut $receiver,
        DteType $type,
        int $folio,
        Carbon $issuedOn,
        int $amountTotal,
    ): bool {
        try {
            $response = $this->soapGateway->query($receiver, 'QueryEstDteAv', 'getEstDteAv', [
                'RutEmisor' => $issuer->num,
                'DvEmisor' => $issuer->vd,
                'RutReceptor' => $receiver->num,
                'DvReceptor' => $receiver->vd,
                'TipoDoc' => (string) $type->value,
                'Folio' => (string) $folio,
                'FchEmis' => $issuedOn->format('d-m-Y'),
                'MontoTotal' => (string) $amountTotal,
                'Token' => $this->soapGateway->token($receiver)->value,
            ]);

            $xmlResponse = $this->xml->simpleXml($response->getEstDteAvResult);
            $sii = $xmlResponse->children('http://www.sii.cl/XMLSchema');
            $body = $sii->RESP_BODY->children('');

            // 0: Aceptado, 3: Aceptado con Reparos
            return in_array((string) $body->CODIGO_ESTADO, ['0', '3'], true);
        } catch (Throwable) {
            return false;
        }
    }
}
