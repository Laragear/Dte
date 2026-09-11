<?php

namespace Laragear\Dte\Testing\Fakes;

use Illuminate\Support\Facades\Facade;

trait FakesBuilder
{
    /**
     * Swap the builder with a fake that captures state instead of persisting.
     *
     * Also binds the concrete builder class so dependency injection
     * receives the fake (e.g., classes that type-hint InvoiceBuilder).
     *
     * @return FakeDocumentBuilder|static
     */
    public static function fake()
    {
        $fakeClass = static::$fakeClass;

        $fake = app($fakeClass);

        static::swap($fakeClass, $fake);

        return $fake;
    }

    /**
     * Restore the original builder binding.
     */
    public static function restore(): void
    {
        static::clearResolvedInstances();
        app()->forgetInstance(static::$fakeClass);
    }
}
