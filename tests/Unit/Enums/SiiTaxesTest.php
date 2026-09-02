<?php

namespace Tests\Unit\Enums;

use Laragear\Dte\Enums\SiiTaxes;
use Tests\TestCase;

class SiiTaxesTest extends TestCase
{
    public function test_iva_rate_returns_config_value(): void
    {
        $this->app->make('config')->set('dte.taxes.iva_rate', 21);

        static::assertSame(21, SiiTaxes::ivaRate());
    }

    public function test_iva_rate_defaults_to_19(): void
    {
        $this->app->make('config')->set('dte.taxes.iva_rate', null);

        static::assertSame(19, SiiTaxes::ivaRate());
    }

    public function test_iva_decimal_returns_float(): void
    {
        $this->app->make('config')->set('dte.taxes.iva_rate', 19);

        static::assertSame(0.19, SiiTaxes::ivaDecimal());
    }
}
