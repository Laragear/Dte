<?php

namespace Tests\Unit\Gateways;

use DateTimeImmutable;
use Laragear\Dte\Gateways\Token;
use Tests\TestCase;

class TokenTest extends TestCase
{
    public function test_creates_from_string_with_ttl(): void
    {
        $token = Token::fromString('foo-bar', 60);

        static::assertSame('foo-bar', $token->value);
        static::assertGreaterThan(new DateTimeImmutable, $token->expiresAt);
    }

}
