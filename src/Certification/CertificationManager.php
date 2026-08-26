<?php

namespace Laragear\Dte\Certification;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Schema\Builder;
use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Certification\TestingSet\TestSet;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Models\SiiAecCession;
use Laragear\Dte\Models\SiiCaf;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Models\SiiDteEnvelopePayload;
use Laragear\Dte\Models\SiiDtePayload;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Models\SiiInboundDocumentPayload;
use Laragear\Dte\Models\SiiInterchangeLog;
use Laragear\Rut\Rut;

class CertificationManager
{
    /**
     * Create a new CertificationManager instance.
     */
    public function __construct(
        protected Container $app,
    ) {
        //
    }

    /**
     * Executes the Test Set (Generate and send DTEs from SII test cases).
     */
    public function testSet(Rut|string $rut, array $dteIds = []): TestSetData
    {
        $data = new TestSetData(is_string($rut) ? Rut::parse($rut) : $rut, $dteIds);

        return $this->app->make(TestSet::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Simulation (Send real recent documents).
     */
    public function simulate(Rut|string $rut, int $quantity = 10, array $documentTypes = []): SimulationData
    {
        $data = new SimulationData(is_string($rut) ? Rut::parse($rut) : $rut, $quantity, $documentTypes);

        return $this->app->make(Simulation::class)->send($data)->thenReturn();
    }

    /**
     * Executes the DTE Interchange Mailbox test.
     */
    public function interchange(
        Rut|string $rut,
        string $source = 'mailbox',
        ?string $filePath = null,
        ?string $xmlContent = null,
        Rut|string|null $signerRut = null,
        ?string $location = null,
    ): InterchangeData {
        $signerRut = is_string($signerRut) ? Rut::parse($signerRut) : $signerRut;
        $data = new InterchangeData(
            is_string($rut) ? Rut::parse($rut) : $rut,
            $source,
            $filePath,
            $xmlContent,
            $signerRut,
            $location,
        );

        return $this->app->make(Interchange::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Print (Send PDF417 samples).
     */
    public function printSample(Rut|string $rut, int $hours = 24): PrintSampleData
    {
        $data = new PrintSampleData(is_string($rut) ? Rut::parse($rut) : $rut, $hours);

        return $this->app->make(PrintSample::class)->send($data)->thenReturn();
    }

    /**
     * Purges all DTE-related database records after certification.
     * Caution: This wipes all documents, envelopes, interchanges, and CAFs.
     */
    public function purgeDatabase(): void
    {
        $this->app->make(Builder::class)->disableForeignKeyConstraints();

        try {
            SiiAecCession::truncate();
            SiiInboundDocumentPayload::truncate();
            SiiInboundDocument::truncate();
            SiiInterchangeLog::truncate();
            SiiDteEnvelopePayload::truncate();
            SiiDteEnvelope::truncate();
            SiiDtePayload::truncate();
            SiiDte::truncate();
            SiiCaf::truncate();
        } finally {
            $this->app->make(Builder::class)->enableForeignKeyConstraints();
        }
    }
}
