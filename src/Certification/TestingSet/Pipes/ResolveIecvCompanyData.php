<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Laragear\Dte\Certification\IecvPurchaseData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Configuration\ConfigurationManager;

use function substr;

/**
 * Resolves the company configuration (resolution date, resolution number,
 * sender RUT, and tax period) from the registered ConfigurationManager
 * before the IECV XML is built.
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

        if ($data->period === '') {
            $data->period = $this->resolvePeriod($data);
        }

        $this->validatePeriodConsistency($data);

        return $next($data);
    }

    /**
     * Resolve the tax period from DTEs or purchase entries.
     */
    protected function resolvePeriod(TestSetData $data): string
    {
        if ($data->dtes->isNotEmpty()) {
            return $data->dtes->first()->issued_on->format('Y-m');
        }

        if ($data->purchaseEntries !== []) {
            $first = $data->purchaseEntries[0];

            return $first instanceof IecvPurchaseData
                ? substr($first->issuedOn, 0, 7)
                : '';
        }

        return '';
    }

    /**
     * Validate that all documents/entries share the same tax period.
     */
    protected function validatePeriodConsistency(TestSetData $data): void
    {
        if ($data->period === '') {
            return;
        }

        if ($data->dtes->isNotEmpty()) {
            foreach ($data->dtes as $dte) {
                $dtePeriod = $dte->issued_on->format('Y-m');

                if ($dtePeriod !== $data->period) {
                    throw new ManuallyFailedException(
                        "All Test Set documents must share the same tax period [{$data->period}], but DTE [{$dte->getKey()}] has period [{$dtePeriod}].",
                    );
                }
            }
        }

        if ($data->purchaseEntries !== []) {
            foreach ($data->purchaseEntries as $index => $entry) {
                $entryPeriod = $entry instanceof IecvPurchaseData
                    ? substr($entry->issuedOn, 0, 7)
                    : '';

                if ($entryPeriod !== $data->period) {
                    throw new ManuallyFailedException(
                        "All purchase entries must share the same tax period [{$data->period}], but entry [{$index}] has period [{$entryPeriod}].",
                    );
                }
            }
        }
    }
}
