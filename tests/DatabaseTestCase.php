<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laragear\Dte\DteServiceProvider;

class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(DteServiceProvider::MIGRATIONS);
    }
}
