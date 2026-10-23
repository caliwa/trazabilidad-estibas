<?php

namespace App\Domain\Audits\Data;

/**
 * The only result the operator gets back from a count.
 */
enum BlindCountOutcome: string
{
    case Approved = 'APROBADA';
    /** Did not match, and the count still has tries left. */
    case Retry = 'REINTENTAR';
    case Discrepancy = 'DISCREPANCIA';
}
