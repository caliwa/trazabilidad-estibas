<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Enums\OrderStage;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Support\Quantities;

/**
 * Progress of one production order: planned in SAP, received in 04 with
 * confirmed receipts, and transferred to the CEDI (05). Quantities are in
 * millionths of the SAP unit (see Quantities).
 */
final readonly class OrderProgress
{
    public function __construct(
        public int $orderId,
        public ?string $sapStatus,
        public ?int $plannedMicro,
        /** Current conversion factor, null when the product has none. */
        public ?int $unitsPerBoxMicro,
        public int $receivedMicro,
        public int $transferredMicro,
        /** Receipts or transfers waiting for reconciliation with SAP. */
        public int $pendingInSap,
        /** Boxes read into the order's pallets (scanned or read by hand), received or not. */
        public int $readBoxes = 0,
        public int $receivedBoxes = 0,
        public int $transferredBoxes = 0,
    ) {}

    /** Boxes the planned quantity fills, the last partial one included; null without a factor. */
    public function plannedBoxes(): ?int
    {
        return $this->plannedMicro === null || $this->unitsPerBoxMicro === null || $this->unitsPerBoxMicro === 0
            ? null
            : Quantities::boxes($this->plannedMicro, $this->unitsPerBoxMicro);
    }

    /** Units in the boxes read, at the current factor; null without one. */
    public function readMicro(): ?int
    {
        return $this->unitsPerBoxMicro === null ? null : $this->readBoxes * $this->unitsPerBoxMicro;
    }

    /** Boxes read against the planned boxes, 0 to 100. */
    public function readPercent(): int
    {
        $planned = $this->plannedBoxes();

        return $planned === null || $planned === 0 ? 0 : (int) min(100, intdiv($this->readBoxes * 100, $planned));
    }

    public function stage(): OrderStage
    {
        if (in_array($this->sapStatus, [ProductionOrderStatus::Closed->value, ProductionOrderStatus::Cancelled->value], true)) {
            return OrderStage::Closed;
        }

        return match (true) {
            $this->plannedMicro === null || $this->receivedMicro === 0 => OrderStage::NoProgress,
            $this->receivedMicro > $this->ceilingMicro() => OrderStage::OverProduced,
            $this->receivedMicro >= $this->plannedMicro => OrderStage::ReadyToClose,
            default => OrderStage::InProgress,
        };
    }

    /** Received in 04 against planned, 0 to 100. */
    public function receivedPercent(): int
    {
        return $this->percentOf($this->receivedMicro);
    }

    /** Transferred to the CEDI against planned, 0 to 100. */
    public function transferredPercent(): int
    {
        return $this->percentOf($this->transferredMicro);
    }

    /** Everything received is already in the CEDI. */
    public function allInCedi(): bool
    {
        return $this->receivedMicro > 0 && $this->transferredMicro >= $this->receivedMicro;
    }

    /**
     * Most that still counts as the planned quantity. Receipts go in whole
     * boxes, so when the plan ends in a partial box (800 at 24 per box is 33
     * boxes and one of 8) receiving that last box is not over-production.
     */
    public function ceilingMicro(): int
    {
        if ($this->plannedMicro === null) {
            return 0;
        }

        return $this->unitsPerBoxMicro === null ? $this->plannedMicro : Quantities::boxes($this->plannedMicro, $this->unitsPerBoxMicro) * $this->unitsPerBoxMicro;
    }

    private function percentOf(int $micro): int
    {
        if ($this->plannedMicro === null || $this->plannedMicro === 0) {
            return 0;
        }

        return (int) min(100, intdiv($micro * 100, $this->plannedMicro));
    }
}
