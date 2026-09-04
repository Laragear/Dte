<?php

namespace Laragear\Dte\Pdf;

use DOMDocument;
use DOMNode;
use InvalidArgumentException;
use Laragear\Dte\Support\LibxmlProxy;
use Laragear\Dte\Support\XmlDomFactory;
use const LIBXML_NONET;

class TedExtractor
{
    /**
     * Create a new Ted Extractor instance.
     */
    public function __construct(
        protected XmlDomFactory $xml,
        protected LibxmlProxy $libxml,
    ) {
        //
    }

    /**
     * Extract the raw <TED>...</TED> element from the compiled XML payload.
     */
    public function extract(string $xml): string
    {
        $document = $this->xml->document();

        $this->loadXml($document, $xml);

        $ted = $this->getTed($document);

        return $document->saveXML($ted)
            ?: throw new InvalidArgumentException('Unable to extract the TED XML.');
    }

    /**
     * Load the XML into the document.
     */
    protected function loadXml(DOMDocument $document, string $xml): void
    {
        $previous = $this->libxml->useInternalErrors(true);

        $loaded = $document->loadXML($xml, LIBXML_NONET);

        $this->libxml->clearErrors();
        $this->libxml->useInternalErrors($previous);

        if (!$loaded) {
            throw new InvalidArgumentException('The XML payload is invalid.');
        }
    }

    /**
     * Retrieve the TED of the document.
     */
    protected function getTed(DOMDocument $document): DOMNode
    {
        $xpath = $this->xml->xpath($document);

        $xpath->registerNamespace('sii', XmlDomFactory::XML_NAMESPACE);

        return $xpath->query('//sii:TED')->item(0)
            ?: throw new InvalidArgumentException('The XML payload does not contain a TED element.');
    }
}
