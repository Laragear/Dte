<?php

namespace Laragear\Dte\Gateways;

use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Exceptions\TokenInvalidException;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Support\TokenAuthenticator;
use RuntimeException;
use function sprintf;

class ReclamoWebserviceGateway
{
    /**
     * Action code for "Aceptación Comercial del Documento".
     */
    public const string ACTION_ACCEPT = 'ACD';

    /**
     * Action code for "Reclamo Comercial al Contenido del Documento".
     */
    public const string ACTION_REJECT = 'RCD';

    /**
     * Action code for "Reclamo por Falta Total de Entrega de Mercaderías".
     */
    public const string ACTION_REJECT_GOODS = 'ERM';

    /**
     * Action code for "Reclamo por Falta Parcial de Entrega de Mercaderías".
     */
    public const string ACTION_REJECT_PARTIAL = 'RFP';

    /**
     * Action code for "Recibo de Mercaderías o Servicios Prestados".
     */
    public const string ACTION_GOODS_RECEIPT = 'RMA';

    /**
     * Create a new Reclamo Webservice Gateway instance.
     */
    public function __construct(
        protected TokenAuthenticator $authenticator,
        protected EnvironmentResolver $environment,
        protected SoapClientFactory $soapClientFactory,
    ) {
        //
    }

    /**
     * Commercially accept a vendor invoice (Aceptación Comercial).
     */
    public function accept(SiiInboundDocument $document): void
    {
        $this->claim($document, static::ACTION_ACCEPT, '');
    }

    /**
     * Reject a vendor invoice commercially (Reclamo al Contenido).
     */
    public function reject(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_REJECT, $reason);
    }

    /**
     * Reject a vendor invoice due to missing goods (Reclamo Falta Total de Mercaderías).
     */
    public function rejectGoods(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_REJECT_GOODS, $reason);
    }

    /**
     * Reject a vendor invoice due to partially missing goods (Reclamo por Falta Parcial).
     */
    public function rejectPartial(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_REJECT_PARTIAL, $reason);
    }

    /**
     * Confirm receipt of goods or services (Acuse de Recibo de Mercaderías).
     */
    public function confirmGoodsReceipt(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_GOODS_RECEIPT, $reason);
    }

    /**
     * Send a claim action against a vendor invoice at the SII.
     */
    protected function claim(SiiInboundDocument $document, string $action, string $reason): void
    {
        $issuer = $document->receiver_rut;

        $this->authenticator->retryWithFreshToken(function () use ($document, $action, $reason, $issuer): void {
            $token = $this->authenticator->token($issuer);
            $baseUrl = $this->environment->resolve()->reclamoBaseUrl();

            if ($baseUrl === null) {
                return;
            }

            $wsdlUrl = $baseUrl.'?wsdl';

            $client = $this->soapClientFactory->createAuthenticatedClient($wsdlUrl, $token);

            $result = $client->__soapCall('ReclamoDoc', [
                [
                    'RutEmisor' => $document->issuer_rut->num,
                    'DvEmisor' => $document->issuer_rut->vd,
                    'TipoDoc' => $document->document_type->value,
                    'Folio' => $document->folio,
                    'AccionDoc' => $action,
                    'MotivoReclamo' => $reason,
                ],
            ]);

            $status = (string) ($result->ReclamoDocResult->status ?? '-1');

            // SII signals an inactive/invalid token with 001/002/003: refresh and retry.
            if (TokenStatus::isNotValid($status)) {
                throw new TokenInvalidException('SII Reclamo WS rejected the authentication token.');
            }

            if ((int) $status !== 0) {
                throw new RuntimeException(sprintf(
                    'SII Reclamo WS returned non-zero status %d for document %s/%d.',
                    (int) $status,
                    $document->issuer_rut->formatBasic(),
                    $document->folio,
                ));
            }
        }, $issuer);
    }
}
