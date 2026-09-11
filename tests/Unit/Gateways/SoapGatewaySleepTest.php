<?php

namespace Laragear\Dte\Gateways;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Rut\Rut;
use Mockery;
use Mockery\MockInterface;
use SoapClient;
use Tests\TestCase;

/**
 * Isolated test class for the namespace fallback trick.
 *
 * This class lives in the Laragear\Dte\Gateways namespace so that when
 * SoapGateway::sleepFor() calls sleep(), PHP resolves it to the
 * no-op sleep() defined in sleep.php instead of the global sleep().
 */
class SoapGatewaySleepTest extends TestCase
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

    protected function mockSoapFlow(callable $seedSetup, callable $tokenSetup): void
    {
        $this->mock(SoapProxy::class,
            static function (MockInterface $mock) use ($seedSetup, $tokenSetup): void {
                $currentWsdl = null;

                $mock->expects('withWsdl')->zeroOrMoreTimes()->andReturnUsing(
                    static function (string $wsdl) use (&$currentWsdl, $mock) {
                        $currentWsdl = $wsdl;

                        return $mock;
                    }
                );

                $mock->expects('build')->zeroOrMoreTimes()->andReturnUsing(
                    static function () use (&$currentWsdl, $seedSetup, $tokenSetup) {
                        $client = Mockery::mock(SoapClient::class);

                        if (str_contains($currentWsdl, 'CrSeed')) {
                            $seedSetup($client);
                        } else {
                            $tokenSetup($client);
                        }

                        return $client;
                    }
                );
            });
    }

    protected function mockOpenSsl(): void
    {
        $this->mock(OpenSslProxy::class, static function (MockInterface $mock): void {
            $mock->expects('readPkcs12String')
                ->zeroOrMoreTimes()
                ->andReturn(['pkey' => 'private-key', 'cert' => 'certificate']);
            $mock->expects('sign')
                ->zeroOrMoreTimes()
                ->andReturn('signature-b64');
            $mock->expects('privateKeyDetails')
                ->zeroOrMoreTimes()
                ->andReturn(['rsa' => ['n' => 'modulus', 'e' => 'AQAB']]);
        });
    }

    public function test_sleep_for_is_called_during_seed_backoff(): void
    {
        // Trick to not hit the native sleep.
        require_once __DIR__.'/sleep.php';

        $issuer = Rut::parse('76.123.456-7');

        $seedBackoff = '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/SiiDte">'
            .'<SII:RESP_HDR><SII:ESTADO>-2</SII:ESTADO></SII:RESP_HDR>'
            .'</SII:RESPUESTA>';

        $seedOk = '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/SiiDte">'
            .'<SII:RESP_HDR><SII:ESTADO>00</SII:ESTADO></SII:RESP_HDR>'
            .'<SII:RESP_BODY><SEMILLA>000000001042</SEMILLA></SII:RESP_BODY>'
            .'</SII:RESPUESTA>';

        $tokenOk = '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/SiiDte">'
            .'<SII:RESP_HDR><SII:ESTADO>00</SII:ESTADO></SII:RESP_HDR>'
            .'<SII:RESP_BODY><TOKEN>sii-token</TOKEN></SII:RESP_BODY>'
            .'</SII:RESPUESTA>';

        $seedCalls = 0;

        $certificate = new DigitalCertificate('fake', 'secret');
        $this->mock(CertificateResolverInterface::class, static function (MockInterface $mock) use ($certificate) {
            $mock->expects('resolve')->zeroOrMoreTimes()->andReturn($certificate);
        });

        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));

        $this->app->bind(SoapGateway::class, SoapGateway::class);

        $this->mockSoapFlow(
            static function (SoapClient $client) use ($seedBackoff, $seedOk, &$seedCalls) {
                $client->expects('getSeed')->times(3)->andReturnUsing(
                    static function () use (&$seedCalls, $seedBackoff, $seedOk) {
                        $seedCalls++;

                        return $seedCalls === 3 ? $seedOk : $seedBackoff;
                    }
                );
            },
            static function (SoapClient $client) use ($tokenOk) {
                $client->expects('getToken')->andReturn($tokenOk);
            }
        );

        $this->mockOpenSsl();

        $gateway = $this->app->make(SoapGateway::class);

        static::assertSame('sii-token', $gateway->authenticate($issuer));
        static::assertSame(3, $seedCalls);
    }
}
