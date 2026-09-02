<?php

namespace Tests\Unit\Support;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory;
use InvalidArgumentException;
use Laragear\Dte\Enums\TokenType;
use Laragear\Dte\Gateways\Token;
use Laragear\Dte\Support\TokenRepository;
use Laragear\Rut\Rut;
use Tests\TestCase;

class TokenRepositoryTest extends TestCase
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

    protected function makeRepository(): TokenRepository
    {
        return $this->app->make(TokenRepository::class);
    }

    public function test_ttl_defaults_to_3600(): void
    {
        $this->app->make('config')->offsetUnset('dte.soap.token_ttl');

        static::assertSame(3600, $this->makeRepository()->ttl(TokenType::Soap));
        static::assertSame(3600, $this->makeRepository()->ttl(TokenType::Rest));
    }

    public function test_ttl_reads_from_config(): void
    {
        $this->app->make('config')->set('dte.soap.token_ttl', 60);

        static::assertSame(60, $this->makeRepository()->ttl(TokenType::Soap));
        static::assertSame(60, $this->makeRepository()->ttl(TokenType::Rest));
    }

    public function test_ttl_throws_if_exceeds_3600(): void
    {
        $this->app->make('config')->set('dte.soap.token_ttl', 7200);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SOAP token TTL must not exceed 3600 seconds (SII token lifetime). Got: 7200');

        $this->makeRepository()->ttl(TokenType::Soap);
    }

    public function test_ttl_throws_if_exceeds_3600_for_rest(): void
    {
        $this->app->make('config')->set('dte.soap.token_ttl', 7200);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REST token TTL must not exceed 3600 seconds (SII token lifetime). Got: 7200');

        $this->makeRepository()->ttl(TokenType::Rest);
    }

    public function test_key_uses_prefix_service_and_raw_rut(): void
    {
        $repository = $this->makeRepository();

        static::assertSame(
            'dte|soap_token|business:761234567',
            $repository->key(TokenType::Soap, Rut::parse('76.123.456-7'))
        );
        static::assertSame(
            'dte|rest_token|business:761234567',
            $repository->key(TokenType::Rest, Rut::parse('76.123.456-7'))
        );
    }

    public function test_key_uses_configured_prefix(): void
    {
        $this->app->make('config')->set('dte.cache.prefix', 'myapp');

        static::assertSame(
            'myapp|soap_token|business:761234567',
            $this->makeRepository()->key(TokenType::Soap, Rut::parse('76.123.456-7'))
        );
    }

    public function test_get_returns_null_when_nothing_is_cached(): void
    {
        static::assertNull(
            $this->makeRepository()->get(TokenType::Soap, Rut::parse('76.123.456-7'))
        );
    }

    public function test_get_returns_cached_token_and_touches_entry(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $repository = $this->makeRepository();

        $repository->put(TokenType::Soap, $issuer, new Token('cached-token', new DateTimeImmutable('+1 hour')));

        $token = $repository->get(TokenType::Soap, $issuer);

        static::assertSame('cached-token', $token?->value);

        // Touch extends the cached window to the full TTL.
        $cache = $this->app->make(Factory::class);
        static::assertSame('cached-token', $cache->get('dte|soap_token|business:761234567')?->value);
    }

    public function test_get_returns_null_for_expired_token(): void
    {
        $issuer = Rut::parse('76.123.456-7');

        $this->app->make(Factory::class)->put(
            'dte|soap_token|business:761234567',
            new Token('expired-token', new DateTimeImmutable('-1 hour')),
        );

        static::assertNull($this->makeRepository()->get(TokenType::Soap, $issuer));
    }

    public function test_forget_removes_the_cached_token(): void
    {
        $issuer = Rut::parse('76.123.456-7');
        $repository = $this->makeRepository();

        $repository->put(TokenType::Soap, $issuer, new Token('cached-token', new DateTimeImmutable('+1 hour')));

        $repository->forget(TokenType::Soap, $issuer);

        static::assertNull(
            $this->app->make(Factory::class)->get('dte|soap_token|business:761234567')
        );
    }
}
