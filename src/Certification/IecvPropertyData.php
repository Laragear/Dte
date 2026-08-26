<?php

namespace Laragear\Dte\Certification;

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
