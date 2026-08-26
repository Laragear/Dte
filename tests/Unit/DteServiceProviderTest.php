<?php

namespace Tests\Unit;

use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\DteServiceProvider;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Pdf\Pdf417Generator;
use Laragear\MetaTesting\InteractsWithServiceProvider;
use Tests\TestCase;

class DteServiceProviderTest extends TestCase
{
    use InteractsWithServiceProvider;

    public function test_merges_config(): void
    {
        static::assertConfigMerged(DteServiceProvider::CONFIG, 'dte');
    }

    public function test_loads_views(): void
    {
        static::assertHasViews(DteServiceProvider::VIEWS, 'dte');
    }

    public function test_registers_the_environment_resolver_as_a_singleton(): void
    {
        static::assertHasSingletons(EnvironmentResolver::class);
    }

    public function test_registers_the_certificate_manager_as_a_singleton(): void
    {
        static::assertHasSingletons(CertificateResolver::class);

        static::assertSame(
            $this->app->make(CertificateResolver::class),
            $this->app->make(CertificateResolverInterface::class),
        );
    }

    public function test_registers_pdf417_generator(): void
    {
        $generator = $this->app->make(Pdf417Generator::class);
        static::assertInstanceOf(Pdf417Generator::class, $generator);
    }

    public function test_publishes_config(): void
    {
        static::assertPublishes($this->app->configPath('dte.php'), 'config');
    }

    public function test_publishes_migrations(): void
    {
        static::assertPublishes($this->app->databasePath('migrations'), 'migrations');
    }
}
