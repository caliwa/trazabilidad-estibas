<?php

namespace App\Domain\Pallets\Data;

use App\Models\Item;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;

final readonly class ResolvedMasters
{
    public function __construct(
        public ProductionOrder $order,
        public Item $item,
        public PalletStandard $standard,
    ) {}
}
