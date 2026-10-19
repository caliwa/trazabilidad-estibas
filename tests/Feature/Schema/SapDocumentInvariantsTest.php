<?php

namespace Tests\Feature\Schema;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Integrations\Sap\Dto\Quantity;
use App\Models\BoxConversionFactor;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

/**
 * RN-07, RN-08, RN-09: one active or confirmed document per pallet and
 * kind, confirmation only with SAP references, closed documents frozen.
 */
class SapDocumentInvariantsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function receiptLine(ProductionReceipt $receipt, Pallet $pallet, array $overrides = []): ProductionReceiptPallet
    {
        $factor = BoxConversionFactor::query()->where('item_id', $pallet->item_id)->first()
            ?? BoxConversionFactor::factory()->create(['item_id' => $pallet->item_id, 'units_per_box' => '12.5']);
        $line = new ProductionReceiptPallet;
        $line->forceFill([
            'production_receipt_id' => $receipt->id,
            'pallet_id' => $pallet->id,
            'boxes' => $pallet->boxes_count,
            'box_conversion_factor_id' => $factor->id,
            'units_per_box' => '12.5',
            'sap_quantity' => Quantity::ofBoxes($pallet->boxes_count, '12.5')->value,
            'item_code' => $pallet->item_code,
            'lot_code' => $pallet->lot_code,
            ...$overrides,
        ])->save();

        return $line;
    }

    public function test_a_pallet_cannot_be_held_by_two_receipts(): void
    {
        $pallet = Pallet::factory()->labeled()->create();
        $first = ProductionReceipt::factory()->create(['production_order_id' => $pallet->production_order_id]);
        $second = ProductionReceipt::factory()->create(['production_order_id' => $pallet->production_order_id]);

        $line = $this->receiptLine($first, $pallet);

        $this->assertDatabaseRejects(fn () => $this->receiptLine($second, $pallet), 'production_receipt_pallets_one_hold');

        // Once SAP proved the first one failed, the pallet is released and can be requested again.
        $line->forceFill(['hold_status' => PalletHold::Released])->save();
        $this->receiptLine($second, $pallet);

        $this->assertSame(2, ProductionReceiptPallet::count());
    }

    public function test_sap_quantity_is_exactly_boxes_times_factor(): void
    {
        $pallet = Pallet::factory()->labeled()->create();
        $receipt = ProductionReceipt::factory()->create(['production_order_id' => $pallet->production_order_id]);

        $this->assertDatabaseRejects(fn () => $this->receiptLine($receipt, $pallet, ['sap_quantity' => '1']), 'production_receipt_pallets_quantities_check');
    }

    public function test_confirmation_requires_doc_entry_and_doc_num(): void
    {
        $receipt = ProductionReceipt::factory()->create();
        $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now()])->save();

        $this->assertDatabaseRejects(
            fn () => $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now()])->save(),
            'production_receipts_confirmed_has_document_check',
        );

        $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now(), 'sap_doc_entry' => 10, 'sap_doc_num' => 20])->save();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->refresh()->status);
    }

    public function test_a_request_cannot_jump_to_confirmed_without_being_sent(): void
    {
        $receipt = ProductionReceipt::factory()->create();

        $this->assertDatabaseRejects(
            fn () => $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now(), 'sent_at' => now(), 'sap_doc_entry' => 1, 'sap_doc_num' => 1])->save(),
            'transición no permitida',
        );
    }

    public function test_closed_documents_and_the_request_itself_are_frozen(): void
    {
        $receipt = ProductionReceipt::factory()->create();

        $this->assertDatabaseRejects(fn () => $receipt->forceFill(['payload_hash' => str_repeat('0', 64)])->save(), 'no se modifica');

        // The rejected change is still dirty in memory; continue from the stored row.
        $receipt = $receipt->fresh() ?? $this->fail();
        $receipt->forceFill(['status' => SapDocumentStatus::Failed, 'failed_at' => now()])->save();

        $this->assertDatabaseRejects(fn () => $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'failed_at' => null])->save(), 'ya está cerrado');
        $this->assertDatabaseRejects(fn () => $receipt->delete(), 'no_delete');
    }

    public function test_one_sap_document_cannot_confirm_two_requests(): void
    {
        foreach ([1, 2] as $i) {
            $receipt = ProductionReceipt::factory()->create();
            $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now()])->save();
            $confirm = fn () => $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now(), 'sap_doc_entry' => 77, 'sap_doc_num' => 88])->save();

            $i === 1 ? $confirm() : $this->assertDatabaseRejects($confirm, 'production_receipts_sap_doc_entry_unique');
        }
    }

    public function test_a_pallet_cannot_be_held_by_two_transfers_and_warehouses_must_differ(): void
    {
        $audit = PalletAudit::factory()->create();
        $pallet = $audit->pallet;
        $factor = BoxConversionFactor::factory()->create(['item_id' => $pallet->item_id]);

        $line = fn (InventoryTransfer $transfer) => (new InventoryTransferPallet)->forceFill([
            'inventory_transfer_id' => $transfer->id,
            'pallet_id' => $pallet->id,
            'pallet_audit_id' => $audit->id,
            'boxes' => $pallet->boxes_count,
            'box_conversion_factor_id' => $factor->id,
            'units_per_box' => '12',
            'sap_quantity' => (string) ($pallet->boxes_count * 12),
            'item_code' => $pallet->item_code,
            'lot_code' => $pallet->lot_code,
        ])->save();

        $line(InventoryTransfer::factory()->create());
        $this->assertDatabaseRejects(fn () => $line(InventoryTransfer::factory()->create()), 'inventory_transfer_pallets_one_hold');

        $this->assertDatabaseRejects(fn () => InventoryTransfer::factory()->create(['to_warehouse' => '04']), 'inventory_transfers_warehouses_check');
    }
}
