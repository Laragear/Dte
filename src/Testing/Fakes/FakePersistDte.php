<?php

namespace Laragear\Dte\Testing\Fakes;

use Closure;
use Laragear\Dte\Actions\PersistDte\DteData;
use Laragear\Dte\Actions\PersistDte\PersistDte;
use Laragear\Dte\Builders\DocumentBuilder;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Models\SiiDtePayload;
use PHPUnit\Framework\Assert;

class FakePersistDte extends PersistDte
{
    /**
     * All pipeline calls made while faking.
     *
     * @var list<array{builder: DocumentBuilder, sync: mixed, isUpdate: bool}>
     */
    protected static array $calls = [];

    /**
     * Capture the pipeline call and return an in-memory SiiDte.
     */
    public function handle(DocumentBuilder $builder, mixed $sync = false, bool $isUpdate = false): SiiDte
    {
        static::$calls[] = compact('builder', 'sync', 'isUpdate');

        $dte = new SiiDte();
        $dte->setRawAttributes($builder->attributes(), true);
        $dte->exists = true;
        $dte->wasRecentlyCreated = true;

        $payload = new SiiDtePayload();
        $payload->data = $builder->payloadData();
        $dte->setRelation('payload', $payload);

        return $dte;
    }

    /**
     * Assert the expected number of pipeline calls.
     */
    public static function assertCalled(?int $times = null): void
    {
        $count = count(static::$calls);

        if ($times === null) {
            if ($count === 0) {
                Assert::fail('PersistDte pipeline was never called.');
            }

            return;
        }

        if ($count !== $times) {
            Assert::fail("Expected PersistDte to be called {$times} times, but it was called {$count} times.");
        }
    }

    /**
     * Assert a pipeline call matches the given predicate.
     */
    public static function assertCalledWith(callable $predicate): void
    {
        foreach (static::$calls as $call) {
            if ($predicate($call)) {
                return;
            }
        }

        Assert::fail('No PersistDte pipeline call matched the given predicate.');
    }

    /**
     * Return all pipeline calls.
     *
     * @return list<array{builder: DocumentBuilder, sync: mixed, isUpdate: bool}>
     */
    public static function calls(): array
    {
        return static::$calls;
    }

    /**
     * Flush all recorded calls.
     */
    public static function flush(): void
    {
        static::$calls = [];
    }
}
