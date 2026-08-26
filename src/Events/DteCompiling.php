<?php

namespace Laragear\Dte\Events;

use Laragear\Dte\Models\SiiDte;

class DteCompiling
{
    /**
     * Create a new Compiling Dte instance.
     */
    public function __construct(public SiiDte $dte)
    {
        //
    }
}
