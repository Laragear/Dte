<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use DOMDocument;
use DOMElement;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Caf\CafParser;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\TimbreSigner;
use RuntimeException;
use function mb_substr;

class GenerateTed
{
    /**
     * Create a Generate TED pipe instance.
     */
    public function __construct(
        protected CafParser $caf,
        protected TimbreSigner $signer,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Generate and sign the tax stamp for the allocated folio.
     *
     * @param  Closure(Compilation): Compilation  $next
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $document = $compilation->requireDocument();

        $ted = $document->createElementNS(XmlDomFactory::XML_NAMESPACE, 'TED');
        $ted->setAttribute('version', '1.0');

        $details = $document->createElementNS(XmlDomFactory::XML_NAMESPACE, 'DD');

        $ted->appendChild($details);

        $this->appendDetails($compilation, $details);
        $this->appendSignature($compilation, $ted, $details);

        $compilation->ted = $ted;

        return $next($compilation);
    }

    /**
     * Append the DD fields in the strict SII sequence.
     */
    protected function appendDetails(Compilation $compilation, DOMElement $details): void
    {
        $dte = $compilation->dte;
        $data = $compilation->payload()->data;
        $receiver = $data['receiver'];

        $this->element($details, 'RE', $dte->issuer_rut->formatBasic());
        $this->element($details, 'TD', $dte->document_type->value);
        $this->element($details, 'F', $dte->folio);
        $this->element($details, 'FE', $data['issued_on']);
        $this->element($details, 'RR', $dte->receiver_rut->formatBasic());
        $this->element($details, 'RSR', mb_substr($receiver['legal_name'], 0, 40));
        $this->element($details, 'MNT', $dte->amount_total);
        $this->element($details, 'IT1', mb_substr($data['items'][0]['name'], 0, 40));

        $details->appendChild($details->ownerDocument->importNode($this->cafNode($compilation), true));

        $this->element($details, 'TSTED', now()->format('Y-m-d\TH:i:s'));
    }

    /**
     * Append the CAF-backed signature over DD.
     */
    protected function appendSignature(Compilation $compilation, DOMElement $ted, DOMElement $details): void
    {
        $caf = $compilation->dte->caf ?? throw new RuntimeException('The DTE does not have an allocated CAF.');
        $privateKey = $this->caf->parse($caf->xml)['private_key'];

        $signature = $this->element($ted, 'FRMT', $this->signer->sign($details, $privateKey));
        $signature->setAttribute('algoritmo', 'SHA1withRSA');
    }

    /**
     * Extract the CAF element from its persisted authorization.
     */
    protected function cafNode(Compilation $compilation): DOMElement
    {
        $caf = $compilation->dte->caf ?? throw new RuntimeException('The DTE does not have an allocated CAF.');
        $document = $this->cafDocument($caf->xml);
        $node = $document->getElementsByTagName('CAF')->item(0);

        return $node instanceof DOMElement
            ? $node
            : throw new RuntimeException('The allocated CAF XML does not contain a CAF element.');
    }

    /**
     * Parse the persisted CAF XML.
     */
    protected function cafDocument(string $xml): DOMDocument
    {
        $document = $this->xml->document(encoding: 'ISO-8859-1');

        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Unable to parse the allocated CAF XML.');
        }

        return $document;
    }

    /**
     * Append a namespaced TED child.
     */
    protected function element(DOMElement $parent, string $name, string|int|null $value): DOMElement
    {
        $element = $parent->ownerDocument->createElementNS(XmlDomFactory::XML_NAMESPACE, $name, (string) $value);
        $parent->appendChild($element);

        return $element;
    }
}
