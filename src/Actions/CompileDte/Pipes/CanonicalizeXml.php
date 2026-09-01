<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlCanonicalizer;
use RuntimeException;

class CanonicalizeXml
{
    /**
     * Create a Canonicalize XML pipe instance.
     */
    public function __construct(
        protected XmlCanonicalizer $canonicalizer,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Canonicalize and reparse the unsigned DTE document.
     *
     * @param  Closure(Compilation): Compilation  $next
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $canonical = $this->canonicalizer->canonicalize($compilation->requireDocument());
        $document = $this->xml->document(encoding: 'ISO-8859-1');

        if (!@$document->loadXML($canonical, LIBXML_NONET)) {
            throw new RuntimeException('Unable to parse the canonical DTE XML.');
        }

        $document->encoding = 'ISO-8859-1';
        $compilation->document = $document;

        return $next($compilation);
    }
}
