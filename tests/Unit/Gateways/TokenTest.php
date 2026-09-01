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

    public function test_checks_if_valid_at_given_date(): void
    {
        $token = new Token('foo', new DateTimeImmutable('+1 hour'));

        static::assertTrue($token->isValidAt(new DateTimeImmutable('+30 minutes')));
        static::assertFalse($token->isValidAt(new DateTimeImmutable('+2 hours')));
    }
}
