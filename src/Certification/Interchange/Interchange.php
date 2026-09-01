<?php

namespace Laragear\Dte\Certification\Interchange;

use Illuminate\Pipeline\Pipeline;
use Laragear\Rut\Rut;

class Interchange extends Pipeline
{
    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [
        Pipes\FetchInterchangeXml::class,
        Pipes\ProcessInboundDte::class,
        Pipes\AcceptAndSendReceipt::class,
    ];

    /**
     * Executes the interchange step for certification using the given RUT.
     */
    public function forRut(Rut $rut): InterchangeData
    {
        return $this->send(new InterchangeData($rut))->thenReturn();
    }
}
