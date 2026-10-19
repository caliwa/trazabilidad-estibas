<?php

namespace App\Domain\Integration\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Lifecycle of a local request for a SAP document (production receipt or
 * inventory transfer).
 *
 * SOLICITADO: persisted, never sent. ENVIANDO: a send started and its
 * outcome is not recorded yet; if a worker dies here the result is unknown,
 * so it can only move to reconciliation, never be sent again blindly.
 */
enum SapDocumentStatus: string
{
    use HasTransitions;

    case Requested = 'SOLICITADO';
    case Sending = 'ENVIANDO';
    case PendingReconciliation = 'PENDIENTE_CONCILIACION';
    case Confirmed = 'CONFIRMADO';
    case Failed = 'FALLIDO';

    public function next(): array
    {
        return match ($this) {
            self::Requested => [self::Sending, self::Failed],
            self::Sending => [self::Confirmed, self::Failed, self::PendingReconciliation],
            // Retry is allowed only after proving in SAP that no document exists.
            self::PendingReconciliation => [self::Confirmed, self::Failed, self::Sending],
            self::Confirmed => [],
            self::Failed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitado',
            self::Sending => 'Enviando',
            self::PendingReconciliation => 'Pendiente de conciliación',
            self::Confirmed => 'Confirmado',
            self::Failed => 'Fallido',
        };
    }

    /**
     * Pallets in a document with this status are held by it.
     */
    public function holdsPallets(): bool
    {
        return $this !== self::Failed;
    }
}
