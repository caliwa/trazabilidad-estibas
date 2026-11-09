<?php

namespace App\Domain\Receipts;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Settings\AppSettings;
use App\Domain\Settings\Setting;
use App\Models\ProductionOrder;
use App\Support\Quantities;
use Illuminate\Support\Facades\DB;

/**
 * How far an order is from its receipt limit: the parameter percentage of
 * the planned quantity (90 by default). What counts is what is received in
 * 04 or on its way: the lines of receipts confirmed or still in SAP's hands
 * (hold ACTIVA or CONFIRMADA), so two requests in a row cannot both slip
 * past it.
 *
 * Below the limit an order takes receipts; the receipt that crosses it
 * still goes. From then on it takes none until the order is closed in SAP,
 * or until a supervisor lets it go on (receipts_unlocked_at), which holds
 * for the rest of the order's life.
 */
class ReceiptLimit
{
    public function __construct(private readonly AppSettings $settings) {}

    public function percent(): int
    {
        return $this->settings->int(Setting::ReceiptLimitPercent);
    }

    /**
     * @return array{limit: int, planned_micro: int|null, committed_micro: int, percent: int|null, reached: bool, unlocked: bool, blocked: bool}
     */
    public function status(ProductionOrder $order, ?int $limit = null, ?int $committed = null): array
    {
        $limit ??= $this->percent();
        $planned = $order->planned_quantity === null ? null : Quantities::toMicro($order->planned_quantity);
        $committed ??= $this->committedMicro($order->id);
        $percent = $planned === null || $planned === 0 ? null : intdiv($committed * 100, $planned);
        // Without a planned quantity there is nothing to measure against: no limit.
        $reached = $planned !== null && $planned > 0 && $committed * 100 >= $planned * $limit;
        $unlocked = $order->receipts_unlocked_at !== null;

        return [
            'limit' => $limit,
            'planned_micro' => $planned,
            'committed_micro' => $committed,
            'percent' => $percent,
            'reached' => $reached,
            'unlocked' => $unlocked,
            'blocked' => $reached && ! $unlocked,
        ];
    }

    public function committedMicro(int $orderId): int
    {
        return $this->committedFor([$orderId])[$orderId] ?? 0;
    }

    /**
     * Received or on its way, for several orders in one query.
     *
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    public function committedFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('production_receipt_pallets as l')
            ->join('production_receipts as r', 'r.id', '=', 'l.production_receipt_id')
            ->whereIn('r.production_order_id', $orderIds)
            ->whereIn('l.hold_status', [PalletHold::Active->value, PalletHold::Confirmed->value])
            ->groupBy('r.production_order_id')
            ->selectRaw('r.production_order_id AS id, sum(l.sap_quantity) AS total')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->id => Quantities::toMicro((string) $row->total)])
            ->all();
    }
}
