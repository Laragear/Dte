<?php

namespace Laragear\Dte\Certification;

use Laragear\Dte\Enums\IecvProperty;

readonly class IecvPropertyData
{
    /**
     * Create a new IECV Property Data instance.
     */
    public function __construct(
        public IecvProperty $property,
        public mixed $value,
    ) {
        //
    }
}
