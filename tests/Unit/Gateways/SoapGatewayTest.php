<?php

namespace Tests\Unit\Gateways;

use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Foundation\Application;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Rut\Rut;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use SoapClient;
use Tests\TestCase;

class SoapGatewayTest extends TestCase
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

    protected function makeGateway(): SoapGateway
    {
        $certificate = new DigitalCertificate('fake', 'secret');

        $this
            ->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn($certificate);

        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $this->mock(SoapProxy::class);
        $this->mock(OpenSslProxy::class);

        return $this->app->make(SoapGateway::class);
    }

    protected function makeEnvironmentResolver(DteEnvironment $environment): EnvironmentResolver
    {
        $app = Mockery::mock(Application::class, static function (MockInterface $mock) use ($environment): void {
            $mock->allows('environment')
                ->with(DteEnvironment::Production->value)
                ->andReturn($environment === DteEnvironment::Production);
            $mock->allows('environment')
                ->withNoArgs()
                ->andReturn($environment->value);
        });

        return new EnvironmentResolver(new Repository([
            'dte' => ['environment' => $environment->value],
        ]), $app);
    }

    public function test_returns_cached_token_when_available(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $cachedToken = new Token('cached-token-value', new DateTimeImmutable('+1 hour'));

        $this->app->make(Factory::class)->put('dte|soap_token|business:761234567', $cachedToken);

        $gateway = $this->makeGateway();

        $token = $gateway->token($issuer);

        static::assertSame('cached-token-value', $token->value);
        static::assertFalse($token->isExpired());
    }

    public function test_authenticates_and_caches_token_when_cache_miss(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $seed = '1234567890';
        $signedSeed = 'signed-seed-base64';
        $tokenValue = 'sii-token-from-server';

        // Create a real certificate using DummyOpenSslProxy
        $certificate = new DigitalCertificate('fake', 'secret');

        // Mock SoapProxy to return a mock SoapClient
        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient
            ->expects('getSeed')
            ->once()
            ->andReturn((object) ['getSeedResult' => $seed]);

        $mockClient
            ->expects('getToken')
            ->once()
            ->with(Mockery::on(function ($arg) use ($signedSeed) {
                return $arg === $signedSeed;
            }))
            ->andReturn((object) ['getTokenResult' => $tokenValue]);

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->once()->andReturnSelf();
            $mock->expects('withOptions')->once()->andReturnSelf();
            $mock->expects('build')->once()->andReturn($mockClient);
        });

        $this->mock(OpenSslProxy::class, static function (MockInterface $mock) use ($seed, $signedSeed): void {
            $mock
                ->expects('readPkcs12String')
                ->zeroOrMoreTimes()
                ->andReturn(['pkey' => 'fake', 'cert' => 'fake'])
                ->getMock()
                ->expects('sign')
                ->once()
                ->with($seed, 'fake')
                ->andReturn($signedSeed);
        });

        $this
            ->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn($certificate);
        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $gateway = $this->app->make(SoapGateway::class);

        $token = $gateway->token($issuer);

        static::assertSame($tokenValue, $token->value);
        static::assertFalse($token->isExpired());

        // Verify token was cached
        $cached = $this->app->make(Factory::class)->get('dte|soap_token|business:761234567');
        static::assertInstanceOf(Token::class, $cached);
        static::assertSame($tokenValue, $cached->value);
    }

    public function test_uses_cache_prefix_from_config(): void
    {
        $issuer = Rut::parse('76.123.456-7');

        // Override config
        $this->app->make('config')->set(['dte.cache.prefix' => 'custom_prefix']);

        $cachedToken = new Token('cached-token', new DateTimeImmutable('+1 hour'));
        $this->app->make(Factory::class)->put('custom_prefix|soap_token|business:761234567', $cachedToken);

        $gateway = $this->makeGateway();

        $token = $gateway->token($issuer);

        static::assertSame('cached-token', $token->value);
    }

    public function test_uses_rut_raw_format_in_cache_key(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $cachedToken = new Token('cached-token', new DateTimeImmutable('+1 hour'));

        // The cache key should use formatRaw() (without dots and dash)
        $this->app->make(Factory::class)->put('dte|soap_token|business:761234567', $cachedToken);

        $gateway = $this->makeGateway();

        $token = $gateway->token($issuer);

        static::assertSame('cached-token', $token->value);
    }

    public function test_reauthenticates_when_cached_token_is_expired(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $expiredToken = new Token('expired-token', new DateTimeImmutable('-1 hour'));
        $newTokenValue = 'new-sii-token';

        $this->app->make(Factory::class)->put('dte|soap_token|business:761234567', $expiredToken);

        $certificate = new DigitalCertificate('fake', 'secret');

        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient
            ->expects('getSeed')
            ->once()
            ->andReturn((object) ['getSeedResult' => 'seed-value']);
        $mockClient
            ->expects('getToken')
            ->once()
            ->andReturn((object) ['getTokenResult' => $newTokenValue]);

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->once()->andReturnSelf();
            $mock->expects('withOptions')->once()->andReturnSelf();
            $mock->expects('build')->once()->andReturn($mockClient);
        });

        $this->mock(OpenSslProxy::class, static function (MockInterface $mock): void {
            $mock
                ->expects('readPkcs12String')
                ->zeroOrMoreTimes()
                ->andReturn(['pkey' => 'fake', 'cert' => 'fake'])
                ->getMock()
                ->expects('sign')
                ->once()
                ->andReturn('signed-seed');
        });

        $this
            ->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn($certificate);
        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $gateway = $this->app->make(SoapGateway::class);

        $token = $gateway->token($issuer);

        static::assertSame($newTokenValue, $token->value);
    }

    public function test_throws_when_no_certificate_resolved(): void
    {
        $issuer = Rut::parse('76.123.456-7');

        $this->mock(CertificateResolverInterface::class, static function (MockInterface $mock) use ($issuer): void {
            $mock->expects('resolve')->zeroOrMoreTimes()->with($issuer)->once()->andReturnNull();
        });

        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $this->mock(SoapProxy::class);
        $this->mock(OpenSslProxy::class);

        $gateway = $this->app->make(SoapGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No digital certificate resolved for issuer 76123456-7');

        $gateway->token($issuer);
    }

    public function test_throws_when_environment_has_no_base_url(): void
    {
        $issuer = Rut::parse('76.123.456-7');

        // Create a real certificate using DummyOpenSslProxy
        $certificate = new DigitalCertificate('fake', 'secret');

        $this->mock(CertificateResolverInterface::class, static function (MockInterface $mock) use (
            $issuer,
            $certificate,
        ): void {
            $mock->expects('resolve')->with($issuer)->once()->andReturn($certificate);
        });

        // Use real EnvironmentResolver with local environment
        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Local));

        $this->mock(SoapProxy::class);
        $this->mock(OpenSslProxy::class);

        $gateway = $this->app->make(SoapGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Cannot authenticate because no SOAP Base URL is available in this environment.');

        $gateway->token($issuer);
    }

    public function test_throws_when_seed_signing_fails(): void
    {
        $issuer = Rut::parse('76.123.456-7');

        // Create a real certificate using DummyOpenSslProxy
        $certificate = new DigitalCertificate('fake', 'secret');

        $mockClient = Mockery::mock(SoapClient::class);
        $mockClient
            ->expects('getSeed')
            ->once()
            ->andReturn((object) ['getSeedResult' => 'seed-value']);

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($mockClient): void {
            $mock->expects('withWsdl')->once()->andReturnSelf();
            $mock->expects('withOptions')->once()->andReturnSelf();
            $mock->expects('build')->once()->andReturn($mockClient);
        });

        $this->mock(OpenSslProxy::class, static function (MockInterface $mock): void {
            $mock
                ->expects('readPkcs12String')
                ->zeroOrMoreTimes()
                ->andReturn(['pkey' => 'fake', 'cert' => 'fake'])
                ->getMock()
                ->expects('sign')
                ->once()
                ->andThrow(new RuntimeException('Failed to sign data with private key.'));
        });

        $this
            ->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn($certificate);
        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $gateway = $this->app->make(SoapGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to sign data with private key.');

        $gateway->token($issuer);
    }
}
