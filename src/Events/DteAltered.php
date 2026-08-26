<?php

namespace Laragear\Dte\Events;

use Illuminate\Database\Eloquent\Model;
use Laragear\Dte\Data\RcvRecord;

class DteAltered
{
    /**
     * Create a new DTE Altered Discrepancy instance.
     */
    public function __construct(
        public readonly Model $model,
        public readonly RcvRecord $record,
    ) {
        //
    }
}
