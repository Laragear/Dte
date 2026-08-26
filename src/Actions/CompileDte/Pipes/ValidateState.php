<?php

namespace Laragear\Dte\Actions\CompileDte\Pipes;

use Closure;
use Laragear\Dte\Actions\CompileDte\Compilation;
use Laragear\Dte\Enums\DteStatus;
use LogicException;

class ValidateState
{
    /**
     * Ensure only pending documents enter compilation.
     *
     * @param  Closure(Compilation): Compilation  $next
     */
    public function handle(Compilation $compilation, Closure $next): Compilation
    {
        $dte = $compilation->dte;

        if ($dte->status === DteStatus::Pending) {
            $updated = $dte->newModelQuery()
                ->whereKey($dte->getKey())
                ->where('status', DteStatus::Pending)
                ->update(['status' => DteStatus::Building]);

            if (! $updated) {
                throw new LogicException('The DTE document is already being processed.');
            }

            $dte->status = DteStatus::Building;
        } elseif ($dte->status !== DteStatus::Building) {
            throw new LogicException('Only pending or building DTE documents may be compiled.');
        }

        return $next($compilation);
    }
}
