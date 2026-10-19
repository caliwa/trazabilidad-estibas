<?php

namespace App\Domain\Integration\Enums;

/**
 * State of a pallet's line inside a SAP document request. A pallet can have
 * at most one ACTIVA or CONFIRMADA line per document kind (enforced by a
 * partial unique index), which is what stops double receipts and transfers.
 */
enum PalletHold: string
{
    case Active = 'ACTIVA';
    case Confirmed = 'CONFIRMADA';
    /** The document failed in SAP and the pallet was released. */
    case Released = 'LIBERADA';
}
