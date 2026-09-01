<?php

namespace Tests;

use Laragear\Dte\DteServiceProvider;
use Laragear\Dte\Facades\Certificate;
use Laragear\Dte\Facades\Dte;
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
            Dte::class,
        ];
    }

    public static function getStub(string $file): string
    {
        return file_get_contents(static::STUBS.'/'.$file);
    }
}
