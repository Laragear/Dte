<?php

namespace Laragear\Dte\Gateways;

use DateTimeImmutable;
use Illuminate\Support\DateFactory;

class Token
{
    /**
     * Create a new Token instance.
     */
    public function __construct(
        public string $value,
        public DateTimeImmutable $expiresAt,
    ) {
        //
    }

    /**
     * Determine whether the token has expired.
     */
    public function isExpired(): bool
    {
        return app(DateFactory::class)->now()->toDateTimeImmutable() >= $this->expiresAt;
    }

    /**
     * Determine whether the toke has not expired.
     */
    public function isNotExpired(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Returns a serializable representation of the object.
     *
     * @return array{value: string, expires_at: int}
     */
    public function __serialize(): array
    {
        return [
            'value' => $this->value,
            'expires_at' => $this->expiresAt->getTimestamp(),
        ];
    }

    /**
     * Fills the object from serialized data.
     *
     * @param  array{value: string, expires_at: int}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->value = $data['value'];
        $this->expiresAt = DateTimeImmutable::createFromTimestamp($data['expires_at']);
    }

    /**
     * Create a token that expires in the given seconds.
     */
    public static function fromString(string $token, int $ttlSeconds): static
    {
        return new self(
            $token, app(DateFactory::class)->now()->addSeconds($ttlSeconds)->toDateTimeImmutable()
        );
    }
}
