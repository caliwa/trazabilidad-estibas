<?php

namespace App\Domain\Integration\Enums;

/**
 * Where a SAP document request stands, seen from the pallets it holds.
 * Each document kind maps these to its own pallet status dimension.
 */
enum DocumentPhase
{
    case Requested;
    case Pending;
    case Confirmed;
    case Failed;
}
