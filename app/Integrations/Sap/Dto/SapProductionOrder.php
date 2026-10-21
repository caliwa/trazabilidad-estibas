<?php

namespace App\Integrations\Sap\Dto;

use App\Integrations\Sap\Enums\ProductionOrderStatus;
use Carbon\CarbonImmutable;

/**
 * Header of a production order as read from SAP, the fields the
 * production order form shows. Which Service Layer properties feed each
 * one is decided when the real connector is written (DP-09).
 */
final readonly class SapProductionOrder
{
    public function __construct(
        public int $docEntry,
        public string $docNum,
        public string $type,
        public ProductionOrderStatus $status,
        public string $itemCode,
        public string $itemDescription,
        public Quantity $plannedQuantity,
        public ?string $uomName,
        public string $warehouse,
        public ?int $priority,
        public ?CarbonImmutable $orderDate,
        public ?CarbonImmutable $startDate,
        public ?CarbonImmutable $dueDate,
        public ?string $userName,
        public ?string $origin,
        public ?string $linkedTo,
        // Item master data the box label needs.
        public ?string $itemBarcode,
        public ?string $itemInventoryUom,
        // "Completed" quantity of the order form, what SAP has received against it so far,
        // as an exact decimal string ("0" before any receipt). Null when SAP did not say.
        public ?string $completedQuantity = null,
    ) {}
}
