<?php

namespace Laragear\Dte\Services;

use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Models\SiiDte;

class FolioAnnulmentService
{
    public function __construct(protected SoapGateway $gateway)
    {
        //
    }

    /**
     * Report damaged, skipped, or annulled folios to the SII.
     */
    public function annul(SiiDte $dte, string $reason = ''): mixed
    {
        return $this->gateway->query(
            $dte->issuer_rut,
            'RegistroAnulacionFolios',
            'anularFolios',
            [
                'RutEmpresa' => $dte->issuer_rut->num,
                'DvEmpresa' => $dte->issuer_rut->vd,
                'TipoDocumento' => $dte->document_type->value,
                'Folio' => $dte->folio,
                'MotivoAnulacion' => $reason,
            ]
        );
    }
}
