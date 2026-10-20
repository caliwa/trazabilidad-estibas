<?php

namespace Database\Factories;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Integrations\Sap\Dto\Quantity;
use App\Models\BoxConversionFactor;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'qr_token' => bin2hex(random_bytes(16)),
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

    /**
     * Received in 04 with the real document behind it: a confirmed receipt
     * and its confirmed line, as RequestProductionReceipts leaves them. An
     * approved count transfers from that line, so counting needs this.
     */
    public function receivedInSap(): static
    {
        return $this->received()->afterCreating(function (Pallet $pallet): void {
            $factor = BoxConversionFactor::query()->where('item_id', $pallet->item_id)->where('status', MasterStatus::Current)->first()
                ?? BoxConversionFactor::factory()->create(['item_id' => $pallet->item_id]);

            $receipt = ProductionReceipt::factory()->create(['production_order_id' => $pallet->production_order_id]);
            $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => 1])->save();
            $docEntry = fake()->unique()->numberBetween(1, 9_999_999);
            $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now(), 'sap_doc_entry' => $docEntry, 'sap_doc_num' => $docEntry])->save();

            (new ProductionReceiptPallet)->forceFill([
                'production_receipt_id' => $receipt->id,
                'pallet_id' => $pallet->id,
                'hold_status' => PalletHold::Confirmed,
                'boxes' => $pallet->boxes_count,
                'box_conversion_factor_id' => $factor->id,
                'units_per_box' => $factor->units_per_box,
                'sap_quantity' => Quantity::ofBoxes($pallet->boxes_count, (string) $factor->units_per_box)->value,
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
                'sap_line_num' => 0,
            ])->save();
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
