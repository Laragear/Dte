<?php

namespace Laragear\Dte\Actions\CreateEnvelope\Pipes;

use Closure;
use DOMException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Actions\CreateEnvelope\Assembly;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlCanonicalizer;
use RuntimeException;

class CanonicalizeEnvelope
{
    /**
     * Create a Canonicalize Envelope pipe instance.
     */
    public function __construct(
        protected Filesystem $file,
        protected XmlCanonicalizer $canonicalizer,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Canonicalize the streamed envelope into a signable document.
     *
     * @param  Closure(Assembly): Assembly  $next
     *
     * @throws DOMException
     */
    public function handle(Assembly $assembly, Closure $next): Assembly
    {
        try {
            $xml = $this->file->get($assembly->requirePath());
        } catch (FileNotFoundException $exception) {
            throw new RuntimeException('Unable to read the temporary envelope XML.', previous: $exception);
        }

        $document = $this->xml->document(encoding: 'ISO-8859-1');

        if (!$document->loadXML($this->canonicalizer->canonicalize($xml), LIBXML_NONET)) {
            throw new RuntimeException('Unable to parse the canonical envelope XML.');
        }

        $document->encoding = 'ISO-8859-1';
        $assembly->document = $document;

        return $next($assembly);
    }
}
