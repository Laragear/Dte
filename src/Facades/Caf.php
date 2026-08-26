<?php

namespace Laragear\Dte\Facades;

use Illuminate\Support\Facades\Facade;
use Laragear\Dte\Caf\CafManager;
use Laragear\Dte\Models\SiiCaf;
use SplFileInfo;

/**
 * @method static SiiCaf store(string $xml)
 * @method static SiiCaf storeFile(string|SplFileInfo $file)
 *
 * @see CafManager
 */
class Caf extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return CafManager::class;
    }
}
