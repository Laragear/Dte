<?php

namespace Tests\Unit\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laragear\Dte\Builders\AecCessionBuilder;
use Laragear\Dte\Enums\DteStatus;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Pdf\PdfBuilder;
use Tests\TestCase;

class SiiDteTest extends TestCase
{
    public function test_relationships_and_methods(): void
    {
        $dte = new SiiDte;

        static::assertInstanceOf(BelongsTo::class, $dte->caf());
        static::assertInstanceOf(BelongsTo::class, $dte->envelope());
        static::assertInstanceOf(HasOne::class, $dte->payload());
        static::assertInstanceOf(HasMany::class, $dte->aecCessions());

        // Mock app container for builders
        $this->mock(AecCessionBuilder::class)->expects('forDte')->andReturnSelf();

        $this->mock(PdfBuilder::class)->expects('forDte')->andReturnSelf();

        static::assertInstanceOf(AecCessionBuilder::class, $dte->cede());
        static::assertInstanceOf(PdfBuilder::class, $dte->pdf());
    }

    public function test_accepted_with_repairs_helpers(): void
    {
        $dte = new SiiDte;

        $dte->status = DteStatus::Pending;
        $dte->repairs = null;
        static::assertFalse($dte->isAcceptedWithRepairs());
        static::assertTrue($dte->isNotAcceptedWithRepairs());

        $dte->status = DteStatus::Accepted;
        $dte->repairs = null;
        static::assertFalse($dte->isAcceptedWithRepairs());
        static::assertTrue($dte->isNotAcceptedWithRepairs());

        $dte->status = DteStatus::Accepted;
        $dte->repairs = [];
        static::assertFalse($dte->isAcceptedWithRepairs());
        static::assertTrue($dte->isNotAcceptedWithRepairs());

        $dte->status = DteStatus::Accepted;
        $dte->repairs = ['rechazados' => 1];
        static::assertTrue($dte->isAcceptedWithRepairs());
        static::assertFalse($dte->isNotAcceptedWithRepairs());
    }
}
