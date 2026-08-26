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
use Laragear\Dte\Certification\TestingSet\TestSet;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Rut\Rut;
use Mockery;
use Tests\DatabaseTestCase;

class CertificationManagerTest extends DatabaseTestCase
{
    public function test_runs_test_set(): void
    {
        $mock = $this->mock(TestSet::class);
        $mock
            ->expects('send')
            ->withArgs(function (TestSetData $data) {
                return $data->rut->format() === '76.123.456-0' && $data->dteIds === [1, 2];
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new TestSetData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->testSet('76.123.456-0', [1, 2]);

        static::assertInstanceOf(TestSetData::class, $result);
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
                return $data->rut->format() === '76.123.456-0' && $data->hours === 48;
            })
            ->andReturnSelf();
        $mock->expects('thenReturn')->andReturn(new PrintSampleData(Rut::parse('76.123.456-0')));

        $manager = $this->app->make(CertificationManager::class);
        $result = $manager->printSample('76.123.456-0', 48);

        static::assertInstanceOf(PrintSampleData::class, $result);
    }

    public function test_purges_database(): void
    {
        // Models use their own connections to truncate. We can simply mock the Schema Builder
        // to prevent actual DB modification errors, or just let it run if it's SQLite.
        // Actually, we can mock the models, or we can just assert it doesn't throw an exception.
        $schemaMock = Mockery::mock(Builder::class);
        $schemaMock->expects('disableForeignKeyConstraints')->once();
        $schemaMock->expects('enableForeignKeyConstraints')->once();
        $this->app->instance(Builder::class, $schemaMock);

        // We will just let the models truncate the SQLite in-memory tables.
        $manager = $this->app->make(CertificationManager::class);
        $manager->purgeDatabase();

        static::assertTrue(true); // If it didn't throw, it passed.
    }
}
