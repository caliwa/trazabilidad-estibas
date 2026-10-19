<?php

namespace App\Domain\Integration\Enums;

/**
 * Status of a versioned master row synchronized from SAP (pallet standards
 * and box conversion factors).
 */
enum MasterStatus: string
{
    case Current = 'VIGENTE';
    case Superseded = 'REEMPLAZADO';
    /** Came from SAP with values that fail validation (zero, negative, missing). */
    case Invalid = 'INVALIDO';
}
