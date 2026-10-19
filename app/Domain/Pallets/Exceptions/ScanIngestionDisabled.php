<?php

namespace App\Domain\Pallets\Exceptions;

use RuntimeException;

/**
 * Production scan ingestion stays off until DP-01 (sensor event id and its
 * uniqueness) is approved.
 */
class ScanIngestionDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La recepción de lecturas está deshabilitada hasta aprobar DP-01.');
    }
}
