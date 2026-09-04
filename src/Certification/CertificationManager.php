<?php

namespace Laragear\Dte\Certification;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Certification\PrintSample\PrintSample;
use Laragear\Dte\Certification\PrintSample\PrintSampleData;
use Laragear\Dte\Certification\Simulation\Simulation;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetEnvelope;
use Laragear\Dte\Certification\TestingSet\TestSetPurchasesBook;
use Laragear\Dte\Certification\TestingSet\TestSetSalesBook;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Environment\EnvironmentResolver;
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
use LogicException;

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
     * Ensure certification operations are permitted in the current environment.
     */
    protected function ensureCertificationAllowed(): void
    {
        $environment = $this->app->make(EnvironmentResolver::class)->resolve();

        if (!$environment->allowsCertification()) {
            throw new LogicException(
                "Certification operations are not permitted in the [{$environment->value}] environment."
            );
        }
    }

    /**
     * Executes the Test Set for Basic Set.
     */
    public function testSet(Rut|string $rut, array $dteIds = []): TestSetData
    {
        $this->ensureCertificationAllowed();

        $data = new TestSetData(Rut::parse($rut), $dteIds);

        return $this->app->make(TestSetEnvelope::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Set using pre-made DTEs provided by the end-user.
     */
    public function testSetUsing(Rut|string $rut, Collection $dtes): TestSetData
    {
        $this->ensureCertificationAllowed();

        $data = new TestSetData(Rut::parse($rut), dtes: $dtes);

        return $this->app->make(TestSetEnvelope::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Set for Purchase Invoice Set.
     */
    public function purchaseInvoiceTestSet(Rut|string $rut, array $dteIds = []): TestSetData
    {
        $this->ensureCertificationAllowed();

        $data = new TestSetData(Rut::parse($rut), $dteIds);

        return $this->app->make(TestSetEnvelope::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Set for Sales Book.
     */
    public function salesBookTestSet(Rut|string $rut, array $dteIds = []): TestSetData
    {
        $this->ensureCertificationAllowed();

        $data = new TestSetData(Rut::parse($rut), $dteIds);

        return $this->app->make(TestSetSalesBook::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Set for Purchases Book.
     */
    public function purchasesBookTestSet(Rut|string $rut, array $dteIds = []): TestSetData
    {
        $this->ensureCertificationAllowed();

        $data = new TestSetData(Rut::parse($rut), $dteIds);

        return $this->app->make(TestSetPurchasesBook::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Simulation (Send real recent documents).
     *
     * @param  int[]|DteType[]  $documentTypes
     */
    public function simulate(Rut|string $rut, int $quantity = 10, array $documentTypes = []): SimulationData
    {
        $this->ensureCertificationAllowed();

        $data = new SimulationData(Rut::parse($rut), $quantity, $documentTypes);

        return $this->app->make(Simulation::class)->send($data)->thenReturn();
    }

    /**
     * Executes a simulation using pre-made DTEs provided by the end-user,
     * bypassing Faker-based generation to better represent real data and cadence.
     */
    public function simulateUsing(Rut|string $rut, Collection $dtes): SimulationData
    {
        $this->ensureCertificationAllowed();

        $data = new SimulationData(Rut::parse($rut), dtes: $dtes);

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
        $this->ensureCertificationAllowed();

        $data = new InterchangeData(
            Rut::parse($rut),
            $source,
            $filePath,
            $xmlContent,
            $signerRut ? Rut::parse($signerRut) : $signerRut,
            $location,
        );

        return $this->app->make(Interchange::class)->send($data)->thenReturn();
    }

    /**
     * Executes the Test Print (Send PDF417 samples).
     *
     * @param  int[]  $dteIds
     */
    public function printSample(Rut|string $rut, array $dteIds = []): PrintSampleData
    {
        $this->ensureCertificationAllowed();

        $data = new PrintSampleData(is_string($rut) ? Rut::parse($rut) : $rut, $dteIds);

        return $this->app->make(PrintSample::class)->send($data)->thenReturn();
    }

    /**
     * Purges all DTE-related database records after certification.
     * Caution: This wipes all documents, envelopes, interchanges, and CAFs.
     */
    public function purgeDatabase(): void
    {
        $this->ensureCertificationAllowed();

        $this->app->make(SchemaBuilder::class)->disableForeignKeyConstraints();

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
            $this->app->make(SchemaBuilder::class)->enableForeignKeyConstraints();
        }
    }
}
