<?php

namespace App\Domain\Audits\Data;

/**
 * The only result the operator gets back from a count.
 */
enum BlindCountOutcome: string
{
    case Approved = 'APROBADA';
    case Discrepancy = 'DISCREPANCIA';
}
