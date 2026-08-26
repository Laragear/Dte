<?php

namespace Laragear\Dte\Events;

use Laragear\Dte\Models\SiiDte;

class DteCompiled
{
    /**
     * Create a new Compiled Dte instance.
     */
    public function __construct(public SiiDte $dte)
    {
        //
    }
}
