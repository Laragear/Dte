<?php

namespace Laragear\Dte\Support;

class LibxmlProxy
{
    /**
     * Disable libxml errors and allow user to fetch error information as needed.
     */
    public function use_internal_errors(?bool $use_errors = null): bool
    {
        return libxml_use_internal_errors($use_errors);
    }

    /**
     * Clear the libxml error buffer.
     */
    public function clear_errors(): void
    {
        libxml_clear_errors();
    }
}
