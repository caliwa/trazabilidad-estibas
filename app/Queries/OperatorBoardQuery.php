<?php

namespace App\Queries;

use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Shared\TraceEventType;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the production operator needs at a glance on the home page: today's
 * box reads counted and not counted, the last reads one by one (green or
 * red), and how the pallets on the line are going. Read-only.
 */
class OperatorBoardQuery
{
    /**
     * @return array{counted: int, not_counted: int, no_read: int, last_hour: int}
     */
    public function today(): array
    {
        $today = CarbonImmutable::now(config()->string('app.timezone'))->startOfDay();
        $notCounted = $this->notCountedQuery($today);

        return [
            'counted' => PalletScanEvent::query()->counted()->where('received_at', '>=', $today)->count(),
            'not_counted' => (clone $notCounted)->count(),
            'no_read' => (clone $notCounted)->where('context->no_read', true)->count(),
            'last_hour' => PalletScanEvent::query()->counted()->where('received_at', '>=', now()->subHour())->count(),
        ];
    }

    /**
     * The last reads, newest first: counted (with the pallet it went to) or not (with why).
     *
     * @return list<array{ok: bool, at: CarbonImmutable, box: string, detail: string, no_read: bool}>
     */
    public function lastReads(int $limit = 12): array
    {
        $counted = PalletScanEvent::query()->counted()->with(['pallet:id,pallet_code,boxes_count,standard_boxes_snapshot', 'boxLabel:id,code'])
            ->latest('received_at')->latest('id')->limit($limit)->get()
            ->map(fn (PalletScanEvent $e) => [
                'ok' => true,
                'at' => CarbonImmutable::instance($e->received_at),
                'box' => $e->boxCodeForDisplay(),
                'detail' => ($e->pallet->pallet_code ?? '').($e->pallet === null ? '' : " · {$e->pallet->boxes_count} / {$e->pallet->standard_boxes_snapshot}"),
                'no_read' => false,
            ]);

        $refused = $this->notCountedQuery(now()->subDay())->latest('occurred_at')->limit($limit)->get(['occurred_at', 'reason', 'context'])
            ->map(fn (TraceEvent $t) => [
                'ok' => false,
                'at' => CarbonImmutable::instance($t->occurred_at),
                'box' => isset($t->context['read']) ? (string) $t->context['read'] : '',
                'detail' => (string) $t->reason,
                'no_read' => (bool) ($t->context['no_read'] ?? false),
            ]);

        return array_values($counted->concat($refused)->sortByDesc(fn (array $r) => $r['at']->getTimestamp() + $r['at']->micro / 1e6)->take($limit)->all());
    }

    /**
     * @return Collection<int, Pallet>
     */
    public function stacking(): Collection
    {
        return Pallet::query()->with('item:id,description')->where('physical_status', PhysicalStatus::Stacking)->orderByDesc('boxes_count')->get()->toBase();
    }

    /**
     * @return array{to_palletize: int, to_label: int}
     */
    public function waiting(): array
    {
        return [
            'to_palletize' => Pallet::query()->where('physical_status', PhysicalStatus::Finished)->count(),
            'to_label' => Pallet::query()->where('physical_status', PhysicalStatus::Palletized)->count(),
        ];
    }

    /**
     * Box reads the fixed line readers did not count (the station's "Caja no contada").
     *
     * @return Builder<TraceEvent>
     */
    private function notCountedQuery(CarbonImmutable $since): Builder
    {
        $readers = Device::query()->where('kind', 'LECTOR')->where('code', '!=', RegisterManualBoxScan::DEVICE_CODE)->pluck('id');

        return TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->where('subject_type', 'device')
            ->whereIn('subject_id', $readers)->where('occurred_at', '>=', $since);
    }
}
