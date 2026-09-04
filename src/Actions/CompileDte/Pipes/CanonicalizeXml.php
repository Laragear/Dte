<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use DOMException;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Support\XmlDomFactory;
use Laragear\Dte\Xml\XmlCanonicalizer;
use RuntimeException;
use Throwable;
use const LIBXML_NONET;

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
     * Handle the incoming DTE compilation.
     *
     * @param  Closure(Compilation): Compilation  $next
     *
     * @throws DOMException
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $canonical = $this->canonicalizer->canonicalize($compilation->requireDocument());
        $document = $this->xml->document(encoding: 'ISO-8859-1');

        try {
            $document->loadXML($canonical, LIBXML_NONET);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to parse the canonical DTE XML.', previous: $e);
        }

        $document->encoding = 'ISO-8859-1';
        $compilation->document = $document;

        return $next($compilation);
    }
}
