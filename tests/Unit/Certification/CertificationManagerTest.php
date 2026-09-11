<?php

namespace Tests\Unit\Certification;

use Illuminate\Database\Schema\Builder;
use Laragear\Dte\Certification\CertificationManager;
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
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Models\SiiDte;
use Laragear\Rut\Rut;
use LogicException;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;

class CertificationManagerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('dte.environment', 'local');
        $this->app->make(EnvironmentResolver::class)->flush();
    }

    public function test_rejects_certification_in_testing_environment(): void
    {
        $this->app->make('config')->set('dte.environment', 'testing');
        $this->app->make(EnvironmentResolver::class)->flush();

        $manager = $this->app->make(CertificationManager::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Certification operations are not permitted in the [testing] environment.');

        $manager->testSet('76.123.456-0');
    }

    public function test_rejects_certification_in_production_environment(): void
    {
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $manager = $this->app->make(CertificationManager::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Certification operations are not permitted in the [production] environment.');

        $manager->testSet('76.123.456-0');
    }

    public function test_runs_test_sets(): void
    {
        $manager = $this->app->make(CertificationManager::class);

        $mockEnvelope = $this->mock(TestSetEnvelope::class);
        $mockEnvelope->expects('send')->twice()->andReturnSelf();
        $mockEnvelope->expects('thenReturn')->twice()->andReturn(new TestSetData(Rut::parse('76.123.456-0')));

        static::assertInstanceOf(TestSetData::class, $manager->testSet('76.123.456-0', [1, 2]));
        static::assertInstanceOf(TestSetData::class, $manager->purchaseInvoiceTestSet('76.123.456-0', [1, 2]));

        $mockSales = $this->mock(TestSetSalesBook::class);
        $mockSales->expects('send')->andReturnSelf();
        $mockSales->expects('thenReturn')->andReturn(new TestSetData(Rut::parse('76.123.456-0')));

        static::assertInstanceOf(TestSetData::class, $manager->salesBookTestSet('76.123.456-0', [1, 2]));

        $mockPurchases = $this->mock(TestSetPurchasesBook::class);
        $mockPurchases->expects('send')->andReturnSelf();
        $mockPurchases->expects('thenReturn')->andReturn(new TestSetData(Rut::parse('76.123.456-0')));

        static::assertInstanceOf(TestSetData::class, $manager->purchasesBookTestSet('76.123.456-0', [1, 2]));
    }

    public function test_runs_simulation(): void
    {
        $mock = $this->mock(Simulation::class);
        $mock
            ->expects('send')
            ->withArgs(function (SimulationData $data) {
                return
                    $data->rut->format() === '76.123.456-0'
                    && $data->quantity === 15
                    && $data->documentTypes === [33, 34];
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new SimulationData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->simulate('76.123.456-0', 15, [33, 34]);

        static::assertInstanceOf(SimulationData::class, $result);
    }

    public function test_runs_simulation_using_dtes(): void
    {
        $dtes = SiiDte::factory()->count(3)->make();

        $mock = $this->mock(Simulation::class);
        $mock
            ->expects('send')
            ->withArgs(function (SimulationData $data) use ($dtes) {
                return
                    $data->rut->format() === '76.123.456-0'
                    && $data->dtes->count() === 3
                    && $data->dtes->pluck('id')->toArray() === $dtes->pluck('id')->toArray();
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new SimulationData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->simulateUsing('76.123.456-0', $dtes);

        static::assertInstanceOf(SimulationData::class, $result);
    }

    public function test_runs_test_set_using_dtes(): void
    {
        $dtes = SiiDte::factory()->count(3)->make();

        $mock = $this->mock(TestSetEnvelope::class);
        $mock
            ->expects('send')
            ->withArgs(function (TestSetData $data) use ($dtes) {
                return
                    $data->rut->format() === '76.123.456-0'
                    && $data->dtes->count() === 3
                    && $data->dtes->pluck('id')->toArray() === $dtes->pluck('id')->toArray();
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new TestSetData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->testSetUsing('76.123.456-0', $dtes);

        static::assertInstanceOf(TestSetData::class, $result);
    }

    public function test_runs_interchange(): void
    {
        $mock = $this->mock(Interchange::class);
        $mock
            ->expects('send')
            ->withArgs(function (InterchangeData $data) {
                return
                    $data->rut->format() === '76.123.456-0'
                    && $data->source === 'file'
                    && $data->filePath === '/path/to/file';
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new InterchangeData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->interchange('76.123.456-0', 'file', '/path/to/file');

        static::assertInstanceOf(InterchangeData::class, $result);
    }

    public function test_runs_print_sample(): void
    {
        $mock = $this->mock(PrintSample::class);
        $mock
            ->expects('send')
            ->withArgs(function (PrintSampleData $data) {
                return $data->rut->format() === '76.123.456-0' && $data->dteIds === [1, 2, 3];
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new PrintSampleData(Rut::parse('76.123.456-0'), [1, 2, 3]));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->printSample('76.123.456-0', [1, 2, 3]);

        static::assertInstanceOf(PrintSampleData::class, $result);
    }

    public function test_purges_database(): void
    {
        // Models use their own connections to truncate. We can simply mock the Schema Builder
        // to prevent actual DB modification errors, or just let it run if it's SQLite.
        // Actually, we can mock the models, or we can just assert it doesn't throw an exception.
        $this->mock(Builder::class, static function (MockInterface $mock): void {
            $mock->expects('disableForeignKeyConstraints');
            $mock->expects('enableForeignKeyConstraints');
        });

        // We will just let the models truncate the SQLite in-memory tables.
        $manager = $this->app->make(CertificationManager::class);
        $manager->purgeDatabase();

        static::assertTrue(true); // If it didn't throw, it passed.
    }
}
