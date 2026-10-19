<?php

namespace Tests\Feature\Schema;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

/**
 * Database-level guarantees on pallets (RN-01, RN-05, RN-06, RN-10, RN-15).
 * The use cases check the same rules first; these prove that a bug or a
 * manual UPDATE still cannot break them.
 */
class PalletInvariantsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    public function test_a_new_pallet_starts_empty_in_stacking(): void
    {
        $pallet = Pallet::factory()->create();

        $this->assertSame(PhysicalStatus::Stacking, $pallet->refresh()->physical_status);
        $this->assertSame(0, $pallet->boxes_count);
        $this->assertSame(ReceiptStatus::None, $pallet->receipt_status);
        $this->assertSame(AuditStatus::Pending, $pallet->audit_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
    }

    public function test_a_pallet_cannot_be_inserted_in_a_later_state(): void
    {
        $this->assertDatabaseRejects(
            fn () => Pallet::factory()->create(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Automatic, 'finished_at' => now()]),
            'una estiba nueva empieza en EN_ESTIBADO',
        );
    }

    public function test_boxes_never_exceed_the_frozen_standard_nor_go_negative(): void
    {
        $pallet = Pallet::factory()->create(['standard_boxes_snapshot' => 10]);

        $this->assertDatabaseRejects(fn () => $pallet->forceFill(['boxes_count' => 11])->save(), 'pallets_boxes_range_check');
        $this->assertDatabaseRejects(fn () => Pallet::factory()->create(['boxes_count' => -1]), 'pallets_boxes_range_check');
    }

    public function test_the_standard_must_be_positive(): void
    {
        $this->assertDatabaseRejects(fn () => Pallet::factory()->create(['standard_boxes_snapshot' => 0]), 'pallets_standard_positive_check');
    }

    public function test_box_count_never_decreases(): void
    {
        $pallet = Pallet::factory()->withBoxes(5)->create();

        $this->assertDatabaseRejects(fn () => $pallet->forceFill(['boxes_count' => 4])->save(), 'no puede disminuir');
    }

    public function test_only_one_open_pallet_per_order_item_and_lot(): void
    {
        $first = Pallet::factory()->create(['lot_code' => 'L1']);
        $same = ['production_order_id' => $first->production_order_id, 'lot_code' => 'L1', 'pallet_standard_id' => $first->pallet_standard_id];

        $this->assertDatabaseRejects(fn () => Pallet::factory()->create($same), 'pallets_one_active');

        // A different lot of the same order is a different pallet.
        Pallet::factory()->create([...$same, 'lot_code' => 'L2']);

        // Once the first one is closed, the next pallet of that combination can open.
        $first->forceFill(['boxes_count' => $first->standard_boxes_snapshot])->save();
        $first->forceFill(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Automatic, 'finished_at' => now()])->save();
        Pallet::factory()->create($same);

        $this->assertSame(3, Pallet::count());
    }

    public function test_pallet_codes_are_unique(): void
    {
        Pallet::factory()->create(['pallet_code' => 'EST-00000001']);

        $this->assertDatabaseRejects(fn () => Pallet::factory()->create(['pallet_code' => 'EST-00000001']), 'pallets_pallet_code_unique');
    }

    public function test_identity_is_immutable(): void
    {
        $pallet = Pallet::factory()->create();

        foreach (['lot_code' => 'OTRO', 'item_code' => 'OTRO', 'production_order_ref' => 'OTRA', 'pallet_code' => 'EST-99999999', 'standard_boxes_snapshot' => 99] as $column => $value) {
            $this->assertDatabaseRejects(fn () => $pallet->fresh()?->forceFill([$column => $value])->save(), 'es inmutable');
        }
    }

    public function test_pallets_are_never_deleted(): void
    {
        $pallet = Pallet::factory()->create();

        $this->assertDatabaseRejects(fn () => $pallet->delete(), 'no_delete');
    }

    public function test_physical_steps_cannot_be_skipped_or_reversed(): void
    {
        $pallet = Pallet::factory()->finished()->create();
        $user = User::factory()->create();

        $this->assertDatabaseRejects(
            fn () => $pallet->forceFill(['physical_status' => PhysicalStatus::Labeled, 'palletized_at' => now(), 'palletized_by' => $user->id, 'labeled_at' => now(), 'labeled_by' => $user->id])->save(),
            'transición no permitida',
        );

        $labeled = Pallet::factory()->labeled()->create();

        $this->assertDatabaseRejects(fn () => $labeled->forceFill(['physical_status' => PhysicalStatus::Palletized, 'labeled_at' => null, 'labeled_by' => null])->save(), 'ya quedó registrado');
    }

    public function test_automatic_close_requires_a_full_pallet(): void
    {
        $pallet = Pallet::factory()->withBoxes(3)->create(['standard_boxes_snapshot' => 10]);

        $this->assertDatabaseRejects(
            fn () => $pallet->forceFill(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Automatic, 'finished_at' => now()])->save(),
            'pallets_automatic_close_is_full_check',
        );
    }

    public function test_manual_close_needs_between_one_box_and_standard_minus_one_and_who_closed(): void
    {
        $user = User::factory()->create();
        $close = fn (Pallet $p, ?int $by) => $p->forceFill(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Manual, 'finished_at' => now(), 'closed_by' => $by])->save();

        $empty = Pallet::factory()->create(['standard_boxes_snapshot' => 10]);
        $this->assertDatabaseRejects(fn () => $close($empty, $user->id), 'pallets_manual_close_is_partial_check');

        $full = Pallet::factory()->withBoxes(10)->create(['standard_boxes_snapshot' => 10]);
        $this->assertDatabaseRejects(fn () => $close($full, $user->id), 'pallets_manual_close_is_partial_check');

        $partial = Pallet::factory()->withBoxes(7)->create(['standard_boxes_snapshot' => 10]);
        $this->assertDatabaseRejects(fn () => $close($partial, null), 'pallets_manual_close_is_partial_check');

        $close($partial, $user->id);
        $this->assertSame(70.0, $partial->refresh()->completionPercent());
    }

    public function test_a_closed_pallet_accepts_no_more_boxes_and_keeps_its_close(): void
    {
        $user = User::factory()->create();
        $pallet = Pallet::factory()->withBoxes(7)->create(['standard_boxes_snapshot' => 10]);
        $pallet->forceFill(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Manual, 'finished_at' => now(), 'closed_by' => $user->id])->save();

        $this->assertDatabaseRejects(fn () => $pallet->forceFill(['boxes_count' => 8])->save(), 'no acepta cajas');
        $this->assertDatabaseRejects(fn () => $pallet->fresh()?->forceFill(['closed_by' => User::factory()->create()->id])->save(), 'ya quedó registrado');
    }

    public function test_each_dimension_needs_the_previous_process(): void
    {
        $palletized = Pallet::factory()->palletized()->create();
        $this->assertDatabaseRejects(fn () => $palletized->forceFill(['receipt_status' => ReceiptStatus::Requested])->save(), 'pallets_receipt_needs_label_check');

        $requested = Pallet::factory()->labeled()->create();
        $requested->forceFill(['receipt_status' => ReceiptStatus::Requested])->save();
        $this->assertDatabaseRejects(fn () => $requested->forceFill(['audit_status' => AuditStatus::Counting])->save(), 'pallets_audit_needs_receipt_check');

        $received = Pallet::factory()->received()->create();
        $this->assertDatabaseRejects(fn () => $received->forceFill(['transfer_status' => TransferStatus::Ready])->save(), 'pallets_transfer_needs_audit_check');
    }

    public function test_confirmed_states_cannot_go_back(): void
    {
        $received = Pallet::factory()->received()->create();
        $this->assertDatabaseRejects(fn () => $received->forceFill(['receipt_status' => ReceiptStatus::Failed])->save(), 'transición no permitida');

        $approved = Pallet::factory()->approved()->create();
        $this->assertDatabaseRejects(fn () => $approved->forceFill(['audit_status' => AuditStatus::Counting])->save(), 'transición no permitida');
    }

    public function test_a_later_standard_change_does_not_touch_existing_pallets(): void
    {
        $pallet = Pallet::factory()->create();
        $snapshot = $pallet->standard_boxes_snapshot;

        PalletStandard::query()->whereKey($pallet->pallet_standard_id)->update(['boxes_per_pallet' => $snapshot + 20]);

        $this->assertSame($snapshot, $pallet->refresh()->standard_boxes_snapshot);
    }

    public function test_quantities_and_qr_token_are_not_serialized(): void
    {
        $pallet = Pallet::factory()->withBoxes(3)->create();

        $serialized = $pallet->refresh()->toArray();

        $this->assertArrayNotHasKey('boxes_count', $serialized);
        $this->assertArrayNotHasKey('standard_boxes_snapshot', $serialized);
        $this->assertArrayNotHasKey('qr_token', $serialized);
    }
}
