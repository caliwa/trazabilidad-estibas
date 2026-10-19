<?php

namespace Database\Factories;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A new pallet always starts in EN_ESTIBADO (the insert trigger enforces
 * it). Later states are reached by walking the real transitions one UPDATE
 * at a time, so the factory exercises the same triggers as the app.
 *
 * @extends Factory<Pallet>
 */
class PalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pallet_code' => 'EST-'.fake()->unique()->numerify('########'),
            'qr_token' => Str::lower(Str::random(32)),
            'production_order_id' => ProductionOrder::factory(),
            'item_id' => fn (array $attributes) => ProductionOrder::query()->whereKey($attributes['production_order_id'])->value('item_id'),
            'production_order_ref' => fn (array $attributes) => ProductionOrder::query()->whereKey($attributes['production_order_id'])->value('ref'),
            'item_code' => fn (array $attributes) => ProductionOrder::query()->whereKey($attributes['production_order_id'])->sole()->item()->value('code'),
            'lot_code' => 'L'.fake()->numerify('######'),
            'pallet_standard_id' => fn (array $attributes) => PalletStandard::factory()->create(['item_id' => $attributes['item_id']])->id,
            'standard_boxes_snapshot' => fn (array $attributes) => PalletStandard::query()->whereKey($attributes['pallet_standard_id'])->value('boxes_per_pallet'),
            'started_at' => now()->subHour(),
        ];
    }

    public function withBoxes(int $boxes): static
    {
        return $this->afterCreating(function (Pallet $pallet) use ($boxes): void {
            $pallet->forceFill(['boxes_count' => $boxes])->save();
        });
    }

    /**
     * Full and closed automatically.
     */
    public function finished(): static
    {
        return $this->afterCreating(function (Pallet $pallet): void {
            $pallet->forceFill([
                'boxes_count' => $pallet->standard_boxes_snapshot,
                'physical_status' => PhysicalStatus::Finished,
                'close_type' => CloseType::Automatic,
                'finished_at' => $pallet->started_at->addMinutes(30),
            ])->save();
        });
    }

    public function palletized(?User $by = null): static
    {
        return $this->finished()->afterCreating(function (Pallet $pallet) use ($by): void {
            $pallet->forceFill([
                'physical_status' => PhysicalStatus::Palletized,
                'palletized_at' => $pallet->finished_at?->addMinutes(5),
                'palletized_by' => ($by ?? User::factory()->create())->id,
            ])->save();
        });
    }

    public function labeled(?User $by = null): static
    {
        return $this->palletized($by)->afterCreating(function (Pallet $pallet) use ($by): void {
            $pallet->forceFill([
                'physical_status' => PhysicalStatus::Labeled,
                'labeled_at' => $pallet->palletized_at?->addMinutes(5),
                'labeled_by' => $by->id ?? $pallet->palletized_by,
            ])->save();
        });
    }

    /**
     * Receipt confirmed in warehouse 04 (flags only; the documents are built in phase 4).
     */
    public function received(): static
    {
        return $this->labeled()->afterCreating(function (Pallet $pallet): void {
            $pallet->forceFill(['receipt_status' => ReceiptStatus::Requested])->save();
            $pallet->forceFill(['receipt_status' => ReceiptStatus::Confirmed])->save();
        });
    }

    public function approved(): static
    {
        return $this->received()->afterCreating(function (Pallet $pallet): void {
            $pallet->forceFill(['audit_status' => AuditStatus::Counting])->save();
            $pallet->forceFill(['audit_status' => AuditStatus::Approved, 'transfer_status' => TransferStatus::Ready])->save();
        });
    }
}
