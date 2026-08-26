<?php

namespace Laragear\Dte\Events;

use Laragear\Dte\Data\RcvRecord;

class DteUnregistered
{
    /**
     * Create a new Unregistered DTE Discovered instance.
     */
    public function __construct(
        public readonly RcvRecord $record,
    ) {
        //
    }
}
