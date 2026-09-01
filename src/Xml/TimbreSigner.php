<?php

namespace Laragear\Dte\Xml;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use InvalidArgumentException;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\XmlDomFactory;

class TimbreSigner
{
    /**
     * Create a Timbre Signer instance.
     */
    public function __construct(
        protected XmlCanonicalizer $canonicalizer,
        protected OpenSslProxy $openSsl,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Sign the canonical TED details using the CAF RSA private key.
     */
    public function sign(DOMElement $details, string $privateKey): string
    {
        if ($details->localName !== 'DD') {
            throw new InvalidArgumentException('The TED signature must target a DD element.');
        }

        $canonical = $this->canonicalizer->canonicalize($this->details($details));

        return $this->openSsl->sign($canonical, $privateKey);
    }

    /**
     * Reconstruct the namespace-free DD fragment required by SII.
     */
    protected function details(DOMElement $details): DOMElement
    {
        $document = $this->xml->document('1.0', 'ISO-8859-1');
        $clone = $this->cloneElement($details, $document);
        $document->appendChild($clone);

        return $clone;
    }

    /**
     * Clone an element without inherited namespaces.
     */
    protected function cloneElement(DOMElement $source, DOMDocument $document): DOMElement
    {
        $clone = $document->createElement((string) $source->localName);

        foreach ($source->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && $attribute->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                $clone->setAttribute((string) $attribute->localName, $attribute->value);
            }
        }

        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $clone->appendChild($this->cloneElement($child, $document));
            } elseif ($child instanceof DOMText) {
                $clone->appendChild($document->createTextNode($child->data));
            }
        }

        return $clone;
    }
}
