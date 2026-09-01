<?php

namespace Laragear\Dte\Enums;

enum ModifierTarget: int
{
    public const self DEFAULT = self::Net;

    /**
     * Exempt amounts (Montos exentos)
     */
    case Exempt = 1;

    /**
     * Net amounts (default); Montos netos (por defecto)
     */
    case Net = 2;

    /**
     * Non-taxable amounts (Montos no afectos)
     */
    case NonTaxable = 3;
}
