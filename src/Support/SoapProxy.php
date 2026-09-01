<?php

namespace Laragear\Dte\Support;

use SoapClient;

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
        ]);

        return new SoapClient($this->wsdl, $options);
    }
}
