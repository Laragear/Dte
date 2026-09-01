<?php

namespace Laragear\Dte\Support;

use DOMDocument;
use DOMXPath;
use SimpleXMLElement;
use XMLWriter;

class XmlDomFactory
{
    public const string XML_NAMESPACE = 'http://www.sii.cl/SiiDte';

    /**
     * Create a new XML DOM Factory instance.
     */
    public function __construct(
        protected LibxmlProxy $libxml,
    ) {
        //
    }

    /**
     * Create a strict DOM document.
     */
    public function document(string $version = '1.0', string $encoding = 'UTF-8'): DOMDocument
    {
        $document = new DOMDocument($version, $encoding);
        $document->formatOutput = false;
        $document->preserveWhiteSpace = true;
        $document->strictErrorChecking = true;

        return $document;
    }

    /**
     * Create a SimpleXML element without external network access.
     */
    public function simpleXml(string $xml, int $options = LIBXML_NONET): SimpleXMLElement
    {
        $previous = $this->libxml->use_internal_errors(true);

        try {
            return new SimpleXMLElement($xml, $options);
        } finally {
            $this->libxml->clear_errors();
            $this->libxml->use_internal_errors($previous);
        }
    }

    /**
     * Create a new DOM XPath instance.
     */
    public function xpath(DOMDocument $document): DOMXPath
    {
        return new DOMXPath($document);
    }

    /**
     * Create a new XML Writer instance.
     */
    public function writer(): XMLWriter
    {
        return new XMLWriter;
    }
}
