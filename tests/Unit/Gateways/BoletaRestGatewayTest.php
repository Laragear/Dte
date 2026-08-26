<?php

namespace Tests\Unit\Gateways;

use DOMElement;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http as HttpFacade;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Enums\EnvelopeStatus;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\BoletaRestGateway;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDteEnvelope;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Rut\Rut;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\DatabaseTestCase;

class BoletaRestGatewayTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HttpFacade::preventStrayRequests();
    }

    protected function makeGateway(DteEnvironment $environment = DteEnvironment::Production): BoletaRestGateway
    {
        $this->mock(CertificateResolverInterface::class)
            ->expects('resolve')
            ->zeroOrMoreTimes()
            ->andReturn(new DigitalCertificate('fake', 'fake'));

        $this->mock(XmlSigner::class)
            ->expects('sign')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (DOMElement $target) {
                $target->setAttribute('signed', 'true');

                return $target;
            });

        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver($environment));

        return $this->app->make(BoletaRestGateway::class);
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

        return new EnvironmentResolver(
            new Repository([
                'dte' => ['environment' => $environment->value],
            ]),
            $app
        );
    }

    protected function makeEnvelope(): SiiDteEnvelope
    {
        return SiiDteEnvelope::factory()->create([
            'status' => EnvelopeStatus::Signed,
        ]);
    }

    public function test_uploads_envelope_and_returns_track_id_in_certification(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(
                ['trackid' => 1014, 'estado' => 'REC', 'codigo' => 0],
                200,
            ),
        ]);

        $gateway = $this->makeGateway(DteEnvironment::Production);
        $trackId = $gateway->upload($envelope, '<EnvioBoleta>...</EnvioBoleta>');

        static::assertSame('1014', $trackId);
    }

    public function test_uploads_envelope_and_returns_track_id_in_production(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>12345</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>TOKEN_PROD</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(
                ['trackid' => 9999, 'estado' => 'REC', 'codigo' => 0],
                200,
            ),
        ]);

        $gateway = $this->makeGateway(DteEnvironment::Production);
        $trackId = $gateway->upload($envelope, '<EnvioBoleta>...</EnvioBoleta>');

        static::assertSame('9999', $trackId);
    }

    public function test_sends_issuer_and_sender_rut_in_upload_request(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(
                ['trackid' => 111, 'estado' => 'REC', 'codigo' => 0],
                200,
            ),
        ]);

        $gateway = $this->makeGateway();
        $gateway->upload($envelope, '<EnvioBoleta/>');

        HttpFacade::assertSent(function (Request $request) use ($envelope): bool {
            if ($request->url() === 'https://api.sii.cl/recursos/v1/boleta.electronica.envio') {
                static::assertStringContainsString($envelope->issuer_rut->num, $request->body());
                static::assertStringContainsString($envelope->sender_rut->num, $request->body());
            }

            return true;
        });
    }

    public function test_sends_exactly_two_lines_for_token_xml(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(
                ['trackid' => 111, 'estado' => 'REC', 'codigo' => 0],
                200,
            ),
        ]);

        $gateway = $this->makeGateway();
        $gateway->upload($envelope, '<EnvioBoleta/>');

        HttpFacade::assertSent(function (Request $request): bool {
            if ($request->url() === 'https://api.sii.cl/recursos/v1/boleta.electronica.token') {
                $body = $request->body();
                static::assertStringStartsWith(
                    '<?xml version="1.0" encoding="UTF-8"?>'
                    ."\n"
                    .'<getToken ID="GetToken" signed="true"><item><Semilla>030530912644</Semilla></item></getToken>',
                    $body,
                );
                // No trailing newlines or extra spaces
                static::assertSame(1, substr_count($body, "\n"));
                static::assertSame(0, substr_count($body, "\r"));
            }

            return true;
        });
    }

    public function test_throws_when_seed_request_fails(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(null, 500),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to get seed from SII.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_when_seed_response_is_invalid(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY></RESP_BODY></RESPUESTA>',
                200,
            ),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid seed response from SII.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_when_token_request_fails(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(null, 500),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to get token from SII.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_when_token_response_is_invalid(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN></RESPUESTATOKEN>',
                200,
            ),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid token response from SII.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_on_unauthorized_upload_response(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(null, 401),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SII Upload rejected the authentication token (401).');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_on_failed_upload_response(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(null, 500),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SII Upload request failed with status 500.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_when_upload_response_has_no_track_id(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio' => HttpFacade::response(
                ['estado' => 'REC', 'codigo' => 0], // no trackid
                200,
            ),
        ]);

        $gateway = $this->makeGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SII Upload response did not contain a valid TrackID.');

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_throws_when_certificate_cannot_be_resolved(): void
    {
        $envelope = $this->makeEnvelope();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
        ]);

        $this->mock(CertificateResolverInterface::class)->expects('resolve')->andReturn(null);

        $this->instance(EnvironmentResolver::class, $this->makeEnvironmentResolver(DteEnvironment::Production));
        $gateway = $this->app->make(BoletaRestGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'No digital certificate resolved for issuer '.$envelope->issuer_rut->formatBasic(),
        );

        $gateway->upload($envelope, '<EnvioBoleta/>');
    }

    public function test_gets_track_status(): void
    {
        $envelope = $this->makeEnvelope();
        $envelope->track_id = '12345';

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            "https://api.sii.cl/recursos/v1/boleta.electronica.envio/{$envelope->issuer_rut->num}-{$envelope->issuer_rut->vd}-12345" => HttpFacade::response(
                ['trackid' => 12345, 'estado' => 'EPR'],
                200,
            ),
        ]);

        $gateway = $this->makeGateway();
        $status = $gateway->trackStatus($envelope);

        static::assertEquals(['trackid' => 12345, 'estado' => 'EPR'], $status);
    }

    public function test_gets_document_status(): void
    {
        $dte = SiiDte::factory()->create([
            'issuer_rut' => '76.543.210-K',
            'receiver_rut' => '66.666.666-6',
            'document_type' => DteType::Receipt,
            'folio' => 500,
            'amount_total' => 1500,
            'created_at' => '2026-05-01 10:00:00',
        ]);

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response(
                '<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response(
                '<RESPUESTATOKEN><TOKEN>P7VQKYLDNHJGP</TOKEN></RESPUESTATOKEN>',
                200,
            ),
            'https://api.sii.cl/recursos/v1/boleta.electronica/76543210-K-39-500/estado*' => HttpFacade::response(
                ['estado' => 'DOK'],
                200,
            ),
        ]);

        $gateway = $this->makeGateway();
        $status = $gateway->documentStatus($dte);

        static::assertEquals(['estado' => 'DOK'], $status);
    }

    /*
     |--------------------------------------------------------------------------
     | Early Returns for Missing Base URL (Non-production environments)
     |--------------------------------------------------------------------------
     */

    public function test_returns_early_without_base_url_on_get_token(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Local);
        static::assertSame('fake-token', $gateway->getToken(Mockery::mock(Rut::class)));
    }

    public function test_returns_early_without_base_url_on_upload(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Local);
        $envelope = SiiDteEnvelope::factory()->create();
        static::assertSame('fake-track-id-'.$envelope->getKey(), $gateway->upload($envelope, 'xml'));
    }

    public function test_returns_early_without_base_url_on_track_status(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Local);
        $envelope = SiiDteEnvelope::factory()->create();
        $response = $gateway->trackStatus($envelope);
        static::assertSame('REC', $response['estado']);
        static::assertSame('Faked status', $response['glosa']);
    }

    public function test_returns_early_without_base_url_on_document_status(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Local);
        $dte = SiiDte::factory()->create();
        $response = $gateway->documentStatus($dte);
        static::assertSame('REC', $response['estado']);
        static::assertSame('Faked status', $response['glosa']);
    }

    /*
     |--------------------------------------------------------------------------
     | Http Error Exceptions
     |--------------------------------------------------------------------------
     */

    public function test_throws_exception_on_track_status_401(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Production);
        $envelope = SiiDteEnvelope::factory()->create();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response('<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response('<RESPUESTA><RESP_BODY><TOKEN>random-token</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio/*' => HttpFacade::response('', 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectexceptionMessageIs('SII Status Query rejected the authentication token (401).');

        $gateway->trackStatus($envelope);
    }

    public function test_throws_exception_on_track_status_500(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Production);
        $envelope = SiiDteEnvelope::factory()->create();

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response('<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response('<RESPUESTA><RESP_BODY><TOKEN>random-token</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.envio/*' => HttpFacade::response('', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectexceptionMessageIs('SII Status Query failed with status 500.');

        $gateway->trackStatus($envelope);
    }

    public function test_throws_exception_on_document_status_401(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Production);
        $dte = SiiDte::factory()->create(['document_type' => DteType::Receipt]);

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response('<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response('<RESPUESTA><RESP_BODY><TOKEN>random-token</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica*' => HttpFacade::response('', 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectexceptionMessageIs('SII Document Status Query rejected the authentication token (401).');

        $gateway->documentStatus($dte);
    }

    public function test_throws_exception_on_document_status_500(): void
    {
        $gateway = $this->makeGateway(DteEnvironment::Production);
        $dte = SiiDte::factory()->create(['document_type' => DteType::Receipt]);

        HttpFacade::fake([
            'https://api.sii.cl/recursos/v1/boleta.electronica.semilla' => HttpFacade::response('<RESPUESTA><RESP_BODY><SEMILLA>030530912644</SEMILLA></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica.token' => HttpFacade::response('<RESPUESTA><RESP_BODY><TOKEN>random-token</TOKEN></RESP_BODY></RESPUESTA>'),
            'https://api.sii.cl/recursos/v1/boleta.electronica*' => HttpFacade::response('', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectexceptionMessageIs('SII Document Status Query failed with status 500.');

        $gateway->documentStatus($dte);
    }
}
