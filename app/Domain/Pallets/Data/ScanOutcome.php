<?php

namespace App\Domain\Pallets\Data;

enum ScanOutcome: string
{
    case Accepted = 'accepted';
    /** Already processed: nothing changed. */
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
}
