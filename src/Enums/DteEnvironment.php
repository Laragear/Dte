<?php

namespace Laragear\Dte\Enums;

use Laragear\Dte\SiiEndpoints;

enum DteEnvironment: string
{
    public const self DEFAULT = self::Local;

    /** Local development runs without contacting SII services. */
    case Local = 'local';

    /** Automated tests run without contacting SII services. */
    case Testing = 'testing';

    /** Production sends legally valid documents to the SII Palena services. */
    case Production = 'production';

    /**
     * Return the SII base URL available for this environment for SOAP endpoints.
     */
    public function soapBaseUrl(): ?string
    {
        return match ($this) {
            self::Production => SiiEndpoints::SOAP_PRODUCTION,
            default => null,
        };
    }

    /**
     * Return the SII base URL available for this environment for REST endpoints.
     */
    public function restBaseUrl(): ?string
    {
        return match ($this) {
            self::Production => SiiEndpoints::REST_PRODUCTION,
            default => null,
        };
    }
}
