<?php

namespace Laragear\Dte\Xml;

use DOMElement;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Support\LibxmlProxy;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\Support\XmlDomFactory;
use RuntimeException;

use function base64_encode;
use function is_array;
use function sha1;

class XmlSigner
{
    /**
     * Create a new XML Signer instance.
     */
    public function __construct(
        protected OpenSslProxy $openSsl,
        protected LibxmlProxy $libxml,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Sign a raw XML string and return the signed XML string.
     *
     * @param  array<string>  $targetIds
     */
    public function signString(string $xml, DigitalCertificate $certificate, array $targetIds = []): string
    {
        $doc = $this->xml->document();
        $previous = $this->libxml->use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET);
        $this->libxml->clear_errors();
        $this->libxml->use_internal_errors($previous);

        if ($targetIds === []) {
            $root = $doc->documentElement
                ?? throw new RuntimeException('Cannot sign: XML document has no root element.');
            $this->sign($root, $certificate);
        } else {
            $xpath = $this->xml->xpath($doc);
            foreach ($targetIds as $id) {
                $target = $xpath->query("//*[@ID=\"$id\"]")->item(0)
                    ?? throw new RuntimeException("Cannot sign: XML document has no element with ID '$id'.");
                $this->sign($target, $certificate);
            }
        }

        return $doc->saveXML();
    }

    /**
     * Apply the SII XMLDSig signature beside the referenced element.
     */
    public function sign(DOMElement $target, DigitalCertificate $certificate): DOMElement
    {
        if ($target->parentNode === null) {
            throw new InvalidArgumentException('The XML signature target must be attached to a document.');
        }

        $id = $target->getAttribute('ID');

        if ($id === '') {
            throw new InvalidArgumentException('The XML signature target must have an ID attribute.');
        }

        $digest = $this->computeDigest($target);
        $signedInfoXml = $this->buildSignedInfoXml($id, $digest);
        $pem = $this->openSsl->readPkcs12String($certificate->pkcs12, $certificate->password);

        $signatureValue = $this->computeSignatureValue($signedInfoXml, $pem['pkey']);
        [$modulus, $exponent] = $this->extractRsaComponents($pem['pkey']);
        $x509b64 = $this->parseX509($pem['cert']);
        $signatureXml = $this->buildSignatureXml($signedInfoXml, $signatureValue, $modulus, $exponent, $x509b64);

        $sigDoc = $this->xml->document();
        $sigDoc->loadXML($signatureXml);
        $sigNode = $target->ownerDocument->importNode($sigDoc->documentElement, true);
        $target->parentNode->appendChild($sigNode);

        return $sigNode;
    }

    /**
     * Compute the digest of the target element.
     */
    protected function computeDigest(DOMElement $target): string
    {
        return base64_encode(sha1($target->C14N(false, false), true));
    }

    /**
     * Build the SignedInfo XML string.
     */
    protected function buildSignedInfoXml(string $id, string $digest): string
    {
        return <<<XML
<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">
<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>
<Reference URI="#{$id}">
<Transforms>
<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
</Transforms>
<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>
<DigestValue>{$digest}</DigestValue>
</Reference>
</SignedInfo>
XML;
    }

    /**
     * Compute the signature value.
     */
    protected function computeSignatureValue(string $signedInfoXml, string $privateKey): string
    {
        $siDoc = $this->xml->document();
        $siDoc->loadXML($signedInfoXml);

        return $this->openSsl->sign($siDoc->documentElement->C14N(false, false), $privateKey);
    }

    /**
     * Extract RSA modulus and exponent from the certificate.
     */
    protected function extractRsaComponents(string $privateKey): array
    {
        $details = $this->openSsl->privateKeyDetails($privateKey);
        $rsa = is_array($details) ? $details['rsa'] ?? null : null;

        if (! is_array($rsa)) {
            throw new RuntimeException('Unable to extract the certificate RSA public key.');
        }

        return [Str::toBase64($rsa['n']), Str::toBase64($rsa['e'])];
    }

    /**
     * Build the full Signature XML string.
     */
    protected function buildSignatureXml(
        string $signedInfoXml,
        string $signatureValue,
        string $modulus,
        string $exponent,
        string $x509b64
    ): string {
        return <<<XML
<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
{$signedInfoXml}
<SignatureValue>{$signatureValue}</SignatureValue>
<KeyInfo>
<KeyValue>
<RSAKeyValue>
<Modulus>{$modulus}</Modulus>
<Exponent>{$exponent}</Exponent>
</RSAKeyValue>
</KeyValue>
<X509Data>
<X509Certificate>{$x509b64}</X509Certificate>
</X509Data>
</KeyInfo>
</Signature>
XML;
    }

    protected function parseX509(string $certificate): string
    {
        $lines = explode("\n", trim($certificate));
        $b64 = '';
        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $b64 .= trim($line);
            }
        }

        return $b64;
    }
}
