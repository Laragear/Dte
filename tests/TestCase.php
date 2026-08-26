<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\DteServiceProvider;
use Laragear\Dte\Facades\Certificate;
use Laragear\Dte\Facades\Dte;
use Laragear\Rut\RutServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use Spatie\LaravelPdf\PdfServiceProvider;

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

    protected function file(): Filesystem
    {
        return $this->resolve(Filesystem::class);
    }
}
