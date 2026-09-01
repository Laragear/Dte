<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Gateways\IecvUploadGateway;
use Laragear\Dte\SiiEndpoints;

class SendTestingIecv
{
    /**
     * Create a new Send Testing Iecv instance.
     */
    public function __construct(protected IecvUploadGateway $gateway)
    {
        //
    }

    /**
     * Handle the incoming test set data.
     */
    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        $this->gateway->upload(
            $data->rut,
            $data->senderRut ?? $data->rut,
            $data->iecvXml,
            SiiEndpoints::SOAP_CERTIFICATION
        );

        return $next($data);
    }
}
