<?php

namespace Tests\Unit\Certificate\Fixtures;

use Laragear\Dte\Support\OpenSslProxy;

class DummyOpenSslProxy extends OpenSslProxy
{
    /**
     * Create a Dummy Open Ssl Proxy instance.
     *
     * @param  list<string>  $extraCertificates
     */
    public function __construct(
        protected int $validFrom,
        protected int $validUntil,
        protected bool $matches = true,
        protected array $extraCertificates = [],
    ) {
        //
    }

    /**
     * Return dummy PKCS#12 certificate contents.
     *
     * @return array{cert: string, pkey: string, extracerts: list<string>}
     */
    public function readPkcs12(string $path, string $password): array
    {
        return [
            'cert' => 'certificate',
            'pkey' => 'private-key',
            'extracerts' => $this->extraCertificates,
        ];
    }

    /**
     * Return dummy X.509 validity metadata.
     *
     * @return array{validFrom_time_t: int, validTo_time_t: int}
     */
    public function parseX509(string $certificate): array
    {
        return [
            'validFrom_time_t' => $this->validFrom,
            'validTo_time_t' => $this->validUntil,
        ];
    }

    /**
     * Return whether the dummy private key matches.
     */
    public function privateKeyMatches(string $certificate, string $privateKey): bool
    {
        return $this->matches;
    }
}
