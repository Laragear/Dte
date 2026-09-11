<?php

namespace Tests;

use Laragear\Dte\DteServiceProvider;
use Laragear\Dte\Facades\Certificate;
use Laragear\Dte\Facades\SiiInvoice;
use Laragear\Dte\Facades\SiiReceipt;
use Laragear\Dte\Facades\SiiCreditNote;
use Laragear\Dte\Facades\SiiDebitNote;
use Laragear\Dte\Facades\SiiDispatchGuide;
use Laragear\Dte\Facades\SiiPurchaseInvoice;
use Laragear\Dte\Facades\SiiInvoiceLiquidation;
use Laragear\Dte\Facades\SiiAec;
use Laragear\Dte\Facades\SiiAecCession;
use Laragear\Rut\RutServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use Spatie\LaravelPdf\PdfServiceProvider;
use function file_get_contents;

class TestCase extends BaseTestCase
{
    public const string STUBS = __DIR__.'/stubs';

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            RutServiceProvider::class,
            DteServiceProvider::class,
            PdfServiceProvider::class,
        ];
    }

    #[Override]
    protected function getPackageAliases($app): array
    {
        return [
            Certificate::class,
            SiiInvoice::class,
            SiiReceipt::class,
            SiiCreditNote::class,
            SiiDebitNote::class,
            SiiDispatchGuide::class,
            SiiPurchaseInvoice::class,
            SiiInvoiceLiquidation::class,
            SiiAec::class,
            SiiAecCession::class,
        ];
    }

    public static function getStub(string $file): string
    {
        return file_get_contents(static::STUBS.'/'.$file);
    }
}
