<?php

namespace Tests\Unit\Mailbox;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Mailbox\RutEmailResolver;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Dte\Support\TokenAuthenticator;
use Laragear\Rut\Rut;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use SoapClient;
use SoapFault;
use Tests\TestCase;

class RutEmailResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    protected function tearDown(): void
    {
        $this->app->make('cache')->flush();

        parent::tearDown();
    }

    protected function makeResolver(
        string $environment = 'certification',
        bool $cacheEnabled = true,
    ): RutEmailResolver {
        $token = new Token('sii-dir-token', new DateTimeImmutable('+1 hour'));
        $this->mock(TokenAuthenticator::class, static function (Mockery\MockInterface $mock) use ($token): void {
            $mock->expects('token')->zeroOrMoreTimes()->andReturn($token);
            $mock->expects('retryWithFreshToken')->zeroOrMoreTimes()
                ->andReturnUsing(fn($request, $issuer) => $request());
        });

        $this->app['config']->set([
            'dte.environment' => $environment,
            'dte.cache.prefix' => 'dte',
            'dte.dim.addresses.cache' => $cacheEnabled,
            'dte.dim.addresses.days' => 30,
        ]);

        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient->expects('__setSoapHeaders')->zeroOrMoreTimes();
        $mockClient
            ->expects('__soapCall')
            ->zeroOrMoreTimes()
            ->with('getEmailByCodigo', Mockery::any())
            ->andReturn((object) [
                'getEmailByCodigoResult' => (object) ['email' => 'dte@empresa.cl'],
            ]);

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->zeroOrMoreTimes()->andReturnSelf();
            $mock->expects('withOptions')->zeroOrMoreTimes()->andReturnSelf();
            $mock->expects('build')->zeroOrMoreTimes()->andReturn($mockClient);
        });

        return $this->app->make(RutEmailResolver::class);
    }

    public function test_returns_null_in_local_environment(): void
    {
        $resolver = $this->makeResolver('local');

        $result = $resolver->resolve(Rut::parse('76.123.456-7'));

        static::assertNull($result);
    }

    public function test_fetches_email_from_sii_in_certification_environment(): void
    {
        $resolver = $this->makeResolver('certification');

        $result = $resolver->resolve(Rut::parse('76.123.456-7'));

        static::assertSame('dte@empresa.cl', $result);
    }

    public function test_fetches_email_from_sii_in_production_environment(): void
    {
        $resolver = $this->makeResolver('production');

        $result = $resolver->resolve(Rut::parse('76.123.456-7'));

        static::assertSame('dte@empresa.cl', $result);
    }

    public function test_caches_the_email_result(): void
    {
        $resolver = $this->makeResolver('certification', true);
        $rut = Rut::parse('76.123.456-7');

        $resolver->resolve($rut);

        $cached = $this->app->make(Factory::class)->get('dte|exchange_email|rut:761234567');

        static::assertSame('dte@empresa.cl', $cached);
    }

    public function test_returns_cached_email_without_calling_sii(): void
    {
        $rut = Rut::parse('76.123.456-7');
        $this->app->make(Factory::class)->put('dte|exchange_email|rut:761234567',
            'cached@empresa.cl');

        $this->mock(SoapProxy::class)->shouldNotReceive('build');
        $this->mock(TokenAuthenticator::class)->shouldNotReceive('token');

        $this->app['config']->set([
            'dte.environment' => 'certification',
            'dte.cache.prefix' => 'dte',
            'dte.dim.addresses.cache' => true,
            'dte.dim.addresses.days' => 30,
        ]);

        $resolver = $this->app->make(RutEmailResolver::class);

        $result = $resolver->resolve($rut);

        static::assertSame('cached@empresa.cl', $result);
    }

    public function test_uses_configured_cache_prefix(): void
    {
        $rut = Rut::parse('76.123.456-7');

        $token = new Token('tok', new DateTimeImmutable('+1 hour'));
        $this->mock(TokenAuthenticator::class, static function (Mockery\MockInterface $mock) use ($token): void {
            $mock->expects('token')->zeroOrMoreTimes()->andReturn($token);
            $mock->expects('retryWithFreshToken')->zeroOrMoreTimes()
                ->andReturnUsing(fn($request, $issuer) => $request());
        });

        $this->app['config']->set([
            'dte.environment' => 'certification',
            'dte.cache.prefix' => 'myapp',
            'dte.dim.addresses.cache' => true,
            'dte.dim.addresses.days' => 30,
        ]);

        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient->expects('__setSoapHeaders');
        $mockClient
            ->expects('__soapCall')
            ->andReturn((object) [
                'getEmailByCodigoResult' => (object) ['email' => 'x@x.cl'],
            ]);

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('build')->andReturn($mockClient);
        });

        $resolver = $this->app->make(RutEmailResolver::class);
        $resolver->resolve($rut);

        static::assertSame('x@x.cl',
            $this->app->make(Factory::class)->get('myapp|exchange_email|rut:761234567'));
    }

    public function test_does_not_cache_when_caching_is_disabled(): void
    {
        $resolver = $this->makeResolver('certification', false);
        $rut = Rut::parse('76.123.456-7');

        $resolver->resolve($rut);

        $cached = $this->app->make(Factory::class)->get('dte|exchange_email|rut:761234567');

        static::assertNull($cached);
    }

    public function test_throws_exception_on_soap_fault(): void
    {
        $token = new Token('tok', new DateTimeImmutable('+1 hour'));
        $this->mock(TokenAuthenticator::class, static function (Mockery\MockInterface $mock) use ($token): void {
            $mock->expects('token')->zeroOrMoreTimes()->andReturn($token);
            $mock->expects('retryWithFreshToken')->zeroOrMoreTimes()
                ->andReturnUsing(fn($request, $issuer) => $request());
        });

        $this->app['config']->set([
            'dte.environment' => 'certification',
            'dte.cache.prefix' => 'dte',
            'dte.dim.addresses.cache' => true,
            'dte.dim.addresses.days' => 30,
        ]);

        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient->expects('__setSoapHeaders');
        $mockClient->expects('__soapCall')->andThrow(new SoapFault('Server', 'Fault'));

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('build')->andReturn($mockClient);
        });

        $resolver = $this->app->make(RutEmailResolver::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SII directory service failed to resolve email for 76123456-7.');

        $resolver->resolve(Rut::parse('76.123.456-7'));
    }
}
