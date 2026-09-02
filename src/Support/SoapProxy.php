<?php

namespace Laragear\Dte\Support;

use SoapClient;
use const WSDL_CACHE_DISK;

class SoapProxy
{
    /**
     * Create a new SOAP Proxy instance.
     *
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected ?string $wsdl = null,
        protected array $options = [],
    ) {
        //
    }

    /**
     * Set the WSDL used to build the SOAP client.
     */
    public function withWsdl(?string $wsdl): static
    {
        $this->wsdl = $wsdl;

        return $this;
    }

    /**
     * Set the options used to build the SOAP client.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Build the configured SOAP client.
     */
    public function build(): SoapClient
    {
        $options = array_merge($this->options, [
            'soap_version' => SOAP_1_1,
            'trace' => 1,
            'exceptions' => true,
            // SII WSDL files can be very huge, so we will cache these on disk when available.
            'cache_wsdl' => WSDL_CACHE_DISK,
        ]);

        return new SoapClient($this->wsdl, $options);
    }
}
