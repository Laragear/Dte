<?php

namespace Laragear\Dte\Gateways;

use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Support\SoapProxy;
use RuntimeException;
use SoapHeader;
use function sprintf;

/**
 * Sends commercial claims (Reclamo al Contenido / Reclamo Falta de Mercadería)
 * for received vendor invoices via the SII ReclamoWebservice (Ley 20.956).
 */
class ReclamoWebserviceGateway
{
    /**
     * Action code for "Rechazo del Contenido" (RCD).
     */
    public const string ACTION_REJECT = 'RCD';

    /**
     * Action code for "Rechazo por Falta Total de Mercaderías" (RFT).
     */
    public const string ACTION_REJECT_GOODS = 'RFT';

    /**
     * Action code for "Aceptación Comercial" (ACD).
     */
    public const string ACTION_ACCEPT = 'ACD';

    public function __construct(
        protected TokenProviderInterface $tokens,
        protected EnvironmentResolver $environment,
        protected SoapProxy $soapProxy,
    ) {
        //
    }

    /**
     * Reject a vendor invoice commercially (Reclamo al Contenido).
     */
    public function reject(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_REJECT, $reason);
    }

    /**
     * Reject a vendor invoice due to missing goods (Reclamo Falta de Mercaderías).
     */
    public function rejectGoods(SiiInboundDocument $document, string $reason = ''): void
    {
        $this->claim($document, static::ACTION_REJECT_GOODS, $reason);
    }

    /**
     * Commercially accept a vendor invoice (Aceptación Comercial).
     */
    public function accept(SiiInboundDocument $document): void
    {
        $this->claim($document, static::ACTION_ACCEPT, '');
    }

    /**
     * Send a claim action against a vendor invoice at the SII.
     */
    protected function claim(SiiInboundDocument $document, string $action, string $reason): void
    {
        $issuer = $document->receiver_rut;
        $token = $this->tokens->token($issuer);
        $baseUrl ??= $this->environment->resolve()->soapBaseUrl();

        if ($baseUrl === null) {
            return;
        }

        $wsdlUrl = $baseUrl.'/DTEWS/ReclamoRecibos.asmx?WSDL';

        $client = $this->soapProxy
            ->withWsdl($wsdlUrl)
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->build();

        $header = new SoapHeader('http://www.sii.cl/ws/', 'Token', $token->value);
        $client->__setSoapHeaders($header);

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

        $status = (int) ($result->ReclamoDocResult->status ?? -1);

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'SII Reclamo WS returned non-zero status %d for document %s/%d.',
                $status,
                $document->issuer_rut->formatBasic(),
                $document->folio,
            ));
        }
    }
}
