<?php

namespace Tests\Unit\Xml;

use Laragear\Dte\Xml\XsdValidator;
use RuntimeException;
use Tests\TestCase;

class XsdValidatorTest extends TestCase
{
    public function test_throws_on_validation_failure(): void
    {
        $validator = $this->app->make(XsdValidator::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("XSD validation failed: Element 'Invalid': No matching global declaration available for the validation root.");

        $validator->validate('<Invalid/>', 'DTE_v10.xsd');
    }

    public function test_throws_when_schema_not_found(): void
    {
        $validator = $this->app->make(XsdValidator::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('XSD schema not found: NonExistent.xsd');

        $validator->validate('<Any/>', 'NonExistent.xsd');
    }
}
