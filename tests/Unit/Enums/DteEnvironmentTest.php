<?php

namespace Tests\Unit\Enums;

use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\SiiEndpoints;
use PHPUnit\Framework\TestCase;
use function array_column;

class DteEnvironmentTest extends TestCase
{
    public function test_it_defines_environments(): void
    {
        static::assertSame(
            [
                'Local' => 'local',
                'Testing' => 'testing',
                'Production' => 'production',
            ],
            array_column(DteEnvironment::cases(), 'value', 'name'),
        );
        static::assertSame(DteEnvironment::Local, DteEnvironment::DEFAULT);
    }

    public function test_pairs_environments_with_sii_base_urls(): void
    {
        static::assertNull(DteEnvironment::Local->soapBaseUrl());
        static::assertNull(DteEnvironment::Local->restBaseUrl());

        static::assertNull(DteEnvironment::Testing->soapBaseUrl());
        static::assertNull(DteEnvironment::Testing->restBaseUrl());

        static::assertSame(SiiEndpoints::SOAP_PRODUCTION, DteEnvironment::Production->soapBaseUrl());
        static::assertSame(SiiEndpoints::REST_PRODUCTION, DteEnvironment::Production->restBaseUrl());
    }
}
