<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Configuration\ConfigurationManager;

/**
 * Resolves the company configuration (resolution date, resolution number,
 * sender RUT, and tax period) from the registered ConfigurationManager
 * before the IECV XML is built.
 *
 * Without this pipe, the IECV builder receives empty/zero/null values for
 * these fields, which either produces invalid XML or triggers a TypeError
 * because the IECV builder requires a non-nullable Rut for the sender.
 */
class ResolveIecvCompanyData
{
    /**
     * Create a new pipe instance.
     */
    public function __construct(protected ConfigurationManager $manager)
    {
        //
    }

    /**
     * Resolve company configuration for the IECV.
     */
    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        if ($data->resolutionDate === '' || $data->resolutionNumber === 0) {
            $issuer = $this->manager->getIssuer($data->rut);

            $data->resolutionDate = $data->resolutionDate !== ''
                ? $data->resolutionDate
                : $issuer->resolutionDate;

            $data->resolutionNumber = $data->resolutionNumber !== 0
                ? $data->resolutionNumber
                : $issuer->resolutionNumber;
        }

        if ($data->senderRut === null) {
            $data->senderRut = $this->manager->hasSenderResolver()
                ? $this->manager->getSender($data->rut)
                : $data->rut;
        }

        if ($data->period === '' && $data->dtes->isNotEmpty()) {
            $data->period = $data->dtes->first()->issued_on->format('Y-m');
        }

        return $next($data);
    }
}
