<?php

namespace Tests\Unit\Gateways;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\RestAuthGateway;
use Laragear\Rut\Rut;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RestAuthGatewayTest extends TestCase
{
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

    public function test_returns_fake_token_when_no_base_url(): void
    {
        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Local));

        $gateway = $this->app->make(RestAuthGateway::class);

        $result = $gateway->fetchToken(Rut::parse('76123456-0'));

        static::assertSame('fake-token', $result);
    }
}
