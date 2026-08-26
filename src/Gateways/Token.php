<?php

namespace Laragear\Dte\Gateways;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\DateFactory;

final readonly class Token
{
    /**
     * Create a new Token instance.
     */
    public function __construct(
        public readonly string $value,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        //
    }

    /**
     * Determine whether the token has expired.
     */
    public function isExpired(): bool
    {
        return app(DateFactory::class)->now('America/Santiago')->toDateTimeImmutable() >= $this->expiresAt;
    }

    /**
     * Determine whether the toke has not expired.
     */
    public function isNotExpired(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Create a token that expires in the given seconds.
     */
    public static function fromString(string $token, int $ttlSeconds): static
    {
        return new self($token,
            app(DateFactory::class)->now('America/Santiago')->addSeconds($ttlSeconds)->toDateTimeImmutable());
    }

    /**
     * Determine whether the token is valid at the given time.
     */
    public function isValidAt(DateTimeInterface $date): bool
    {
        return $date < $this->expiresAt;
    }
}
