<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Events\DteCompiling;

class FireDteCompilingEvent
{
    /**
     * Create a new Fire Compiling Dte Event instance.
     */
    public function __construct(protected Dispatcher $event)
    {
        //
    }

    /**
     * Fire the compiling event.
     *
     * @param  Closure(Compilation): Compilation  $next
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $this->event->dispatch(new DteCompiling($compilation->dte));

        return $next($compilation);
    }
}
