<?php

namespace Laragear\Dte\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Support\TokenAuthenticator;
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
        protected TokenAuthenticator $authenticator,
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
            return $this->authenticator->retryWithFreshToken(function () use (
                $issuer,
                $receiver,
                $type,
                $folio,
                $issuedOn,
                $amountTotal
            ): bool {
                $token = $this->authenticator->token($receiver);

                $response = $this->soapGateway->query($token, 'QueryEstDteAv', 'getEstDteAv', [
                    'RutEmisor' => $issuer->num,
                    'DvEmisor' => $issuer->vd,
                    'RutReceptor' => $receiver->num,
                    'DvReceptor' => $receiver->vd,
                    'TipoDoc' => (string) $type->value,
                    'Folio' => (string) $folio,
                    'FchEmis' => $issuedOn->format('d-m-Y'),
                    'MontoTotal' => (string) $amountTotal,
                    'Token' => $token->value,
                ]);

                $xml = is_object($response) ? (string) $response->getEstDteAvResult : (string) $response;

                // SII returns 001/002/003 for an invalid token — signal the trait to refresh.
                if ($this->isTokenInvalidStatus($xml)) {
                    throw new TokenInvalidException('SII SOAP token was invalidated (001/002/003).');
                }

                $xmlResponse = $this->xml->simpleXml($xml);
                $sii = $xmlResponse->children('http://www.sii.cl/XMLSchema');
                $body = $sii->RESP_BODY->children('');

                // 0: Aceptado, 3: Aceptado con Reparos
                return in_array((string) $body->CODIGO_ESTADO, ['0', '3'], true);
            }, $receiver);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checks if the XML response indicates an invalid/expired SOAP token.
     *
     * SII returns 001 (inactive), 002 (invalid) or 003 (invalid) for a token
     * that must be refreshed by re-authenticating.
     */
    protected function isTokenInvalidStatus(string $xml): bool
    {
        return Str::contains($xml, [
            '<ESTADO>001</ESTADO>',
            '<ESTADO>002</ESTADO>',
            '<ESTADO>003</ESTADO>',
        ]);
    }
}
