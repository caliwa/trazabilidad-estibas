<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Enums\OrderStage;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Support\Quantities;

/**
 * Progress of one production order: SAP data next to what the floor did.
 * Quantities in units are kept in millionths (see Quantities); boxes are
 * whole boxes.
 */
final readonly class OrderProgress
{
    public function __construct(
        public int $orderId,
        public ?string $sapStatus,
        public ?int $plannedMicro,
        public ?string $uom,
        /** Current conversion factor, null when the product has none. */
        public ?int $unitsPerBoxMicro,
        public ?int $sapCompletedMicro,
        public int $labels,
        public int $boxesRead,
        public int $palletsStacking,
        public int $palletsFinished,
        public int $palletsLabeled,
        public int $receivedBoxes,
        public int $receivedMicro,
        public int $approvedBoxes,
        public int $transferredBoxes,
        public int $transferredMicro,
        public int $receiptsInFlight,
        public int $receiptsPending,
        public int $transfersPending,
        public int $discrepanciesOpen,
        /** Labeled pallets with no receipt yet. */
        public int $labeledNotReceived,
    ) {}

    public function stage(): OrderStage
    {
        if (in_array($this->sapStatus, [ProductionOrderStatus::Closed->value, ProductionOrderStatus::Cancelled->value], true)) {
            return OrderStage::Closed;
        }

        if ($this->plannedMicro === null) {
            return OrderStage::NotConsulted;
        }

        return match (true) {
            $this->receivedMicro > $this->ceilingMicro() => OrderStage::OverProduced,
            $this->receivedMicro >= $this->plannedMicro => OrderStage::ReadyToClose,
            default => OrderStage::InProgress,
        };
    }

    /**
     * Most that still counts as the planned quantity. Receipts go in whole
     * boxes, so when the plan ends in a partial box (800 at 24 per box is 33
     * boxes and one of 8) receiving that last box is not over-production.
     */
    public function ceilingMicro(): int
    {
        $boxes = $this->plannedBoxes();

        return $boxes === null ? (int) $this->plannedMicro : $boxes * (int) $this->unitsPerBoxMicro;
    }

    /** Received against planned, 0 to 100 (over 100 is shown as 100 with the over-production alert). */
    public function percent(): int
    {
        if ($this->plannedMicro === null || $this->plannedMicro === 0) {
            return 0;
        }

        return (int) min(100, intdiv($this->receivedMicro * 100, $this->plannedMicro));
    }

    public function plannedBoxes(): ?int
    {
        return $this->plannedMicro === null || $this->unitsPerBoxMicro === null ? null : Quantities::boxes($this->plannedMicro, $this->unitsPerBoxMicro);
    }

    /** Boxes in units with the current factor, when there is one. */
    public function unitsOf(int $boxes): ?int
    {
        return $this->unitsPerBoxMicro === null ? null : $boxes * $this->unitsPerBoxMicro;
    }

    /**
     * What needs someone's attention, most important first.
     *
     * @return list<array{level: 'danger'|'warning'|'success', text: string}>
     */
    public function alerts(): array
    {
        $alerts = [];
        $uom = $this->uom ?? 'unidades';
        $stage = $this->stage();

        if ($stage === OrderStage::OverProduced) {
            $over = Quantities::format($this->receivedMicro - (int) $this->plannedMicro);
            $alerts[] = ['level' => 'danger', 'text' => "Se recibieron {$over} {$uom} por encima de lo planificado, más de lo que llenan sus cajas. Revisar antes de cerrar la OF en SAP."];
        }

        if ($stage === OrderStage::ReadyToClose) {
            $extra = $this->receivedMicro - (int) $this->plannedMicro;
            $alerts[] = ['level' => 'success', 'text' => 'Los recibos confirmados ya cubren lo planificado. Se puede cerrar la OF en SAP.'
                .($extra > 0 ? ' La última caja va completa en el recibo: '.Quantities::format($extra)." {$uom} más que lo planificado." : '')];
        }

        if ($this->sapCompletedMicro !== null && $this->sapCompletedMicro !== $this->receivedMicro) {
            $alerts[] = ['level' => 'warning', 'text' => 'SAP reporta '.Quantities::format($this->sapCompletedMicro)." {$uom} completadas y la aplicación confirmó ".Quantities::format($this->receivedMicro).'. Puede haber recibos hechos por fuera o pendientes.'];
        }

        if ($this->receiptsPending > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "{$this->receiptsPending} recibo(s) pendiente(s) de conciliar con SAP."];
        }

        if ($this->transfersPending > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "{$this->transfersPending} transferencia(s) a 05 pendiente(s) de conciliar con SAP."];
        }

        if ($this->discrepanciesOpen > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "{$this->discrepanciesOpen} discrepancia(s) de conteo abierta(s)."];
        }

        if ($this->boxesRead > $this->labels) {
            $alerts[] = ['level' => 'warning', 'text' => "Se leyeron {$this->boxesRead} cajas y solo se generaron {$this->labels} etiquetas de caja."];
        }

        return $alerts;
    }
}
