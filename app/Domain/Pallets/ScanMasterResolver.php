<?php

namespace App\Domain\Pallets;

use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Pallets\Data\ResolvedMasters;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use Carbon\CarbonInterface;

/**
 * Checks a scan against the local copy of the SAP masters and returns
 * what the pallet needs, or why the event cannot be counted.
 *
 * The lot is only format-checked: whether lots must exist in SAP before
 * production is part of DP-02.
 */
class ScanMasterResolver
{
    public const LOT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,49}$/';

    public function resolve(ScanEventData $data, CarbonInterface $at): ResolvedMasters|ScanRejection
    {
        $order = ProductionOrder::query()->with('item')->where('ref', $data->productionOrderRef)->first();

        if ($order === null) {
            return ScanRejection::UnknownOrder;
        }

        if (! $order->is_open) {
            return ScanRejection::ClosedOrder;
        }

        $item = $order->item;

        if ($item->code !== $data->itemCode) {
            return ScanRejection::ItemMismatch;
        }

        if (! $item->active) {
            return ScanRejection::InactiveItem;
        }

        if (preg_match(self::LOT_PATTERN, $data->lotCode) !== 1) {
            return ScanRejection::InvalidLot;
        }

        $standard = PalletStandard::query()
            ->where('item_id', $item->id)
            ->where('status', MasterStatus::Current)
            ->first();

        if ($standard === null) {
            $hasInvalid = PalletStandard::query()->where('item_id', $item->id)->where('status', MasterStatus::Invalid)->exists();

            return $hasInvalid ? ScanRejection::InvalidStandard : ScanRejection::MissingStandard;
        }

        if (($standard->valid_from !== null && $standard->valid_from->greaterThan($at))
            || ($standard->valid_to !== null && $standard->valid_to->lessThanOrEqualTo($at))) {
            return ScanRejection::ExpiredStandard;
        }

        return new ResolvedMasters($order, $item, $standard);
    }
}
