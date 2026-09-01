<?php

namespace Tests\Unit\Environment;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnvironmentResolverTest extends TestCase
{
    /**
     * @return array{local: array{string, string, DteEnvironment}, testing: array{string, string, DteEnvironment}, 'certification override': array{string, string, DteEnvironment}, production: array{string, string, DteEnvironment}, 'invalid non-production': array{string, string, DteEnvironment}}
     */
    public static function providesValidEnvironments(): array
    {
        return [
            'local' => ['local', 'local', DteEnvironment::Local],
            'testing' => ['testing', 'testing', DteEnvironment::Testing],
            'production' => ['production', 'production', DteEnvironment::Production],
            'invalid non-production' => ['testing', 'invalid', DteEnvironment::Testing],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function providesUnsafeEnvironments(): array
    {
        return [

            'testing with production DTE' => ['testing', 'production'],
            'invalid app with production DTE' => ['staging', 'production'],
        ];
    }

    protected function resolver(string $appEnvironment, ?string $dteEnvironment): EnvironmentResolver
    {
        $app = Mockery::mock(Application::class);
        $app->allows('environment')->with(DteEnvironment::Production->value)->andReturn($appEnvironment === 'production');
        $app->allows('environment')->withNoArgs()->andReturn($appEnvironment);

        return new EnvironmentResolver(new Repository([
            'dte' => ['environment' => $dteEnvironment],
        ]), $app);
    }

    #[DataProvider('providesValidEnvironments')]
    public function test_resolves_the_environment(
        string $appEnvironment,
        string $dteEnvironment,
        DteEnvironment $expected,
    ): void {
        $resolver = $this->resolver($appEnvironment, $dteEnvironment);

        static::assertSame($expected, $resolver->resolve());
    }

    public function test_falls_back_to_the_application_environment(): void
    {
        $resolver = $this->resolver('testing', null);

        static::assertSame(DteEnvironment::Testing, $resolver->resolve());
    }

    #[DataProvider('providesUnsafeEnvironments')]
    public function test_rejects_crossing_the_production_boundary(
        string $appEnvironment,
        string $dteEnvironment,
    ): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('APP_ENV and DTE_ENV must both be production or both be non-production.');

        $this->resolver($appEnvironment, $dteEnvironment)->resolve();
    }
}
