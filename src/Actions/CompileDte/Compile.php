<?php

namespace Laragear\Dte\Actions\CompileDte;

use Illuminate\Pipeline\Pipeline;
use Laragear\Dte\Models\SiiDte;

/**
 * @method Compilation thenReturn()
 */
class Compile extends Pipeline
{
    /**
     * The compilation stages.
     *
     * @var class-string[]
     */
    protected $pipes = [
        Pipes\FireDteCompilingEvent::class,
        Pipes\ValidateState::class,
        Pipes\AcquireFolio::class,
        Pipes\BuildXml::class,
        Pipes\GenerateTed::class,
        Pipes\ApplyTedToDom::class,
        Pipes\CanonicalizeXml::class,
        Pipes\ApplyDigitalSignature::class,
        Pipes\FireDteCompiledEvent::class,
    ];

    /**
     * Send the DTE being compiled.
     */
    public function forDte(SiiDte $dte): Compilation
    {
        return $this->send(new Compilation($dte))->thenReturn();
    }
}
