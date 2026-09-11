<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Console\ManuallyFailedException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certification\IecvBuilder;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlSigner;
use Laragear\Dte\Xml\XsdValidator;
use RuntimeException;

class OutputIecvPurchases
{
    /**
     * Create a new Output Iecv instance.
     */
    public function __construct(
        protected Application $app,
        protected DateFactory $date,
        protected Filesystem $file,
        protected XmlSigner $signer,
        protected CertificateResolver $certificate,
        protected IecvBuilder $builder,
        protected XmlDomFactory $xml,
        protected XsdValidator $xsd,
    ) {
        //
    }

    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        if ($data->purchaseEntries === []) {
            throw new ManuallyFailedException(
                'No purchase entries provided. Pass IecvPurchaseData entries to purchasesBookTestSet().',
            );
        }

        $certificate = $this->certificate->resolve($data->rut) ?? throw new RuntimeException(
            "No certificate was found for [$data->rut]."
        );

        $unsignedXml = $this->buildXml($data);
        $this->xsd->validate($unsignedXml, 'LibroCV_v10.xsd');
        $document = $this->parseDocument($unsignedXml);

        $this->signer->sign($this->getEnvioLibroElement($document), $certificate);

        $data->iecvXml = $document->saveXml();

        return $next($data);
    }

    /**
     * Parses the IECV XML string into a DOMDocument.
     */
    protected function parseDocument(string $xml): DOMDocument
    {
        $document = $this->xml->document(encoding: 'ISO-8859-1');

        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Unable to parse the IECV XML.');
        }

        return $document;
    }

    /**
     * Finds the EnvioLibro DOMElement inside the parsed document.
     */
    protected function getEnvioLibroElement(DOMDocument $document): DOMElement
    {
        $nodes = $this->xml->xpath($document)->query('//EnvioLibro');
        $target = $nodes !== false ? $nodes->item(0) : null;

        if (!$target instanceof DOMElement) {
            throw new RuntimeException('Unable to find EnvioLibro in IECV XML.');
        }

        return $target;
    }

    /**
     * Builds the XML as a string ready to be signed.
     */
    protected function buildXml(TestSetData $data): string
    {
        return $this->builder->buildPurchases(
            $data->purchaseEntries,
            $data->period,
            $data->resolutionDate,
            $data->resolutionNumber,
            $data->rut,
            $data->senderRut,
            $data->properties,
        );
    }
}
