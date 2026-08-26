<?php

namespace Laragear\Dte\Certification\Interchange\Pipes;

use Closure;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Services\DteClaimService;
use RuntimeException;

class AcceptAndSendReceipt
{
    /**
     * Create a new pipe instance.
     */
    public function __construct(
        protected CertificateResolver $certificates,
        protected DteClaimService $claimService,
    ) {
        //
    }

    /**
     * Handle the incoming interchange data.
     */
    public function handle(InterchangeData $data, Closure $next): InterchangeData
    {
        $signerRut = $data->signerRut ?? $data->rut;
        $location = $data->location ?? 'Santiago';

        $certificate = $this->certificates->resolve($data->rut);

        if ($certificate === null) {
            throw new RuntimeException("Could not resolve digital certificate for {$data->rut->formatBasic()}.");
        }

        $this->claimService->accept(
            document: $data->inboundDocument,
            signer: $signerRut,
            location: $location,
            certificate: $certificate,
        );

        return $next($data);
    }
}
