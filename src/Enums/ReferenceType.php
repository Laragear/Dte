<?php

namespace Laragear\Dte\Enums;

enum ReferenceType: string
{
    /** Purchase Order (Orden de Compra) */
    case PurchaseOrder = '801';

    /** Order Note (Nota de pedido) */
    case OrderNote = '802';

    /** Contract (Contrato) */
    case Contract = '803';

    /** Resolution (Resolución) */
    case Resolution = '804';

    /** ChileCompra Process (Proceso ChileCompra) */
    case ChileCompraProcess = '805';

    /** ChileCompra File (Ficha ChileCompra) */
    case ChileCompraFile = '806';

    /** DUS */
    case Dus = '807';

    /** Bill of Lading (B/L) */
    case BillOfLading = '808';

    /** Air Waybill (AWB) */
    case AirWaybill = '809';

    /** MIC/DTA */
    case MicDta = '810';

    /** Waybill (Carta de Porte) */
    case Waybill = '811';

    /** SNA Resolution (Resolución del SNA donde califica Servicios de Exportación) */
    case SnaResolution = '812';

    /** Passport (Pasaporte) */
    case Passport = '813';

    /** Deposit Certificate (Certificado de Depósito Bolsa Prod. Chile) */
    case DepositCertificate = '814';

    /** Pledge Voucher (Vale de Prenda Bolsa Prod. Chile) */
    case PledgeVoucher = '815';

    /** Test Set (Set de pruebas) */
    case TestSet = 'SET';

    /** Service Entry Sheet (Hoja de entrada de servicios) */
    case ServiceEntrySheet = 'HES';
}
