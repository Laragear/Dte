<?php

namespace Tests\Unit\Console\Commands;

use DateTimeImmutable;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Models\SiiInboundDocument;
use Laragear\Dte\Support\SoapProxy;
use Laragear\Dte\Support\TokenAuthenticator;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SoapClient;
use Tests\DatabaseTestCase;
use function now;

class RejectExpiringPhantomInvoicesCommandTest extends DatabaseTestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_continues_on_failure_and_logs_error(): void
    {
        // Faking config and tokens
        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        $expiredDoc1 = SiiInboundDocument::factory()->create([
            'status' => InboundDteStatus::PhantomPending,
            'created_at' => now()->subDays(7),
        ]);

        $expiredDoc2 = SiiInboundDocument::factory()->create([
            'status' => InboundDteStatus::PhantomPending,
            'created_at' => now()->subDays(7),
        ]);

        $this
            ->mock(LoggerInterface::class)
            ->expects('error')
            ->withArgs(static function (string $message, array $context) use ($expiredDoc1): bool {
                static::assertSame('Failed to reject phantom invoice.', $message);
                static::assertSame($expiredDoc1->id, $context['document_id']);
                static::assertSame('API Error', $context['error']);
                static::assertIsArray($context['trace']);

                return true;
            });

        $client = $this->mock(SoapClient::class, static function (MockInterface $mock) use (
            $expiredDoc1,
            $expiredDoc2,
        ): void {
            $mock->expects('__setSoapHeaders')->twice();

            $mock
                ->expects('__soapCall')
                ->with('ReclamoDoc', Mockery::on(function ($args) use ($expiredDoc1) {
                    return $args[0]['RutEmisor'] === $expiredDoc1->issuer_rut->num;
                }))
                ->andThrow(new RuntimeException('API Error'))
                ->once();

            $mock
                ->expects('__soapCall')
                ->with('ReclamoDoc', Mockery::on(function ($args) use ($expiredDoc2) {
                    return $args[0]['RutEmisor'] === $expiredDoc2->issuer_rut->num;
                }))
                ->andReturn((object) ['ReclamoDocResult' => (object) ['status' => 0]])
                ->once();
        });

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($client): void {
            $mock->expects('withWsdl')->twice()->andReturnSelf();
            $mock->expects('build')->twice()->andReturn($client);
        });

        $this
            ->mock(TokenAuthenticator::class, static function (MockInterface $mock): void {
                $mock->expects('token')->twice()->andReturn(new Token('fake', new DateTimeImmutable('+1 hour')));
                $mock->expects('retryWithFreshToken')->twice()->andReturnUsing(fn($request, $issuer) => $request());
            });

        $this
            ->artisan('dte:reject-phantom-invoices')
            ->expectsOutput('Rejected 1 phantom invoices. Failed: 1.')
            ->assertSuccessful();

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'id' => $expiredDoc1->id,
            'status' => InboundDteStatus::PhantomPending,
        ]);

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'id' => $expiredDoc2->id,
            'status' => InboundDteStatus::CommercialRejected,
        ]);
    }

    public function test_outputs_info_if_no_documents_found(): void
    {
        $this
            ->artisan('dte:reject-phantom-invoices')
            ->expectsOutput('No expiring phantom invoices found.')
            ->assertSuccessful();
    }

    public function test_rejects_phantom_invoices_older_than_threshold(): void
    {
        // Faking config and tokens
        $this->app['env'] = 'production';
        $this->app->make('config')->set('app.env', 'production');
        $this->app->make('config')->set('dte.environment', 'production');
        $this->app->make(EnvironmentResolver::class)->flush();

        // Created 7 days ago
        $expiredDoc = SiiInboundDocument::factory()->create([
            'status' => InboundDteStatus::PhantomPending,
            'created_at' => now()->subDays(7),
        ]);

        // Created 5 days ago (should not be processed if threshold is 6)
        $newDoc = SiiInboundDocument::factory()->create([
            'status' => InboundDteStatus::PhantomPending,
            'created_at' => now()->subDays(5),
        ]);

        $client = $this->mock(SoapClient::class, static function (MockInterface $mock) use ($expiredDoc): void {
            $mock->expects('__setSoapHeaders')->once();
            $mock
                ->expects('__soapCall')
                ->with('ReclamoDoc', Mockery::on(function ($args) use ($expiredDoc) {
                    return $args[0]['RutEmisor'] === $expiredDoc->issuer_rut->num && $args[0]['AccionDoc'] === 'RCD';
                }))
                ->andReturn((object) ['ReclamoDocResult' => (object) ['status' => 0]])
                ->once();
        });

        $this->mock(SoapProxy::class, static function (MockInterface $mock) use ($client): void {
            $mock->expects('withWsdl')->andReturnSelf();
            $mock->expects('build')->andReturn($client);
        });

        $this
            ->mock(TokenAuthenticator::class, static function (MockInterface $mock): void {
                $mock->expects('token')->andReturn(new Token('fake', new DateTimeImmutable('+1 hour')));
                $mock->expects('retryWithFreshToken')->andReturnUsing(fn($request, $issuer) => $request());
            });

        $this
            ->artisan('dte:reject-phantom-invoices')
            ->expectsOutput('Rejected 1 phantom invoices. Failed: 0.')
            ->assertSuccessful();

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'id' => $expiredDoc->id,
            'status' => InboundDteStatus::CommercialRejected,
        ]);

        $this->assertDatabaseHas(SiiInboundDocument::class, [
            'id' => $newDoc->id,
            'status' => InboundDteStatus::PhantomPending,
        ]);
    }
}
