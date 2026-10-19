<?php

namespace Tests\Feature\Pages;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Enums\Role;
use App\Integrations\Sap\Dto\Quantity;
use App\Models\BoxConversionFactor;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use App\Queries\PalletBoardQuery;
use App\Queries\PalletFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PalletBoardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->viewer = $this->userWithRole(Role::Viewer);
    }

    /**
     * A pallet received into 04 with a confirmed SAP receipt.
     */
    private function receivedWithDocument(int $docNum): Pallet
    {
        $pallet = Pallet::factory()->received()->create();
        $receipt = ProductionReceipt::factory()->create(['production_order_id' => $pallet->production_order_id]);
        $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now()])->save();
        $receipt->forceFill(['status' => SapDocumentStatus::Confirmed, 'confirmed_at' => now(), 'sap_doc_entry' => $docNum + 1, 'sap_doc_num' => $docNum])->save();
        $factor = BoxConversionFactor::factory()->create(['item_id' => $pallet->item_id]);
        (new ProductionReceiptPallet)->forceFill([
            'production_receipt_id' => $receipt->id, 'pallet_id' => $pallet->id, 'hold_status' => PalletHold::Confirmed,
            'boxes' => $pallet->boxes_count, 'box_conversion_factor_id' => $factor->id, 'units_per_box' => '12',
            'sap_quantity' => Quantity::ofBoxes($pallet->boxes_count, '12')->value, 'item_code' => $pallet->item_code, 'lot_code' => $pallet->lot_code,
        ])->save();

        return $pallet;
    }

    public function test_access_follows_pallets_view_and_logistics_operators_never_see_the_board(): void
    {
        $this->actingAs($this->viewer)->get(route('pallets.index'))->assertOk();
        $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('pallets.index'))->assertOk();
        $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('pallets.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Role::ProductionOperator))->get(route('pallets.index'))->assertForbidden();
    }

    public function test_every_filter_narrows_the_list(): void
    {
        $item = Item::factory()->create(['code' => 'PT-ROJO', 'description' => 'Salsa roja 500 g']);
        $a = Pallet::factory()->withBoxes(5)->create(['pallet_code' => 'EST-00000001', 'lot_code' => 'L-A', 'production_order_id' => ProductionOrder::factory()->for($item)->create(['ref' => 'OP-ROJA'])->id]);
        $b = Pallet::factory()->labeled()->create(['pallet_code' => 'EST-00000002', 'lot_code' => 'L-B', 'started_at' => now()->subDays(10)]);
        $c = $this->receivedWithDocument(4711);

        $codes = fn (array $f) => app(PalletBoardQuery::class)->paginate(new PalletFilters(...$f))->pluck('pallet_code')->all();

        $this->assertSame([$a->pallet_code], $codes(['code' => '0001']));
        $this->assertSame([$a->pallet_code], $codes(['order' => 'roja']));
        $this->assertSame([$a->pallet_code], $codes(['item' => 'salsa']));
        $this->assertSame([$a->pallet_code], $codes(['item' => 'pt-rojo']));
        $this->assertSame([$b->pallet_code], $codes(['lot' => 'L-B']));
        // Received pallets are physically labeled too; newest first.
        $this->assertSame([$c->pallet_code, $b->pallet_code], $codes(['physical' => PhysicalStatus::Labeled->value]));
        $this->assertSame([$c->pallet_code], $codes(['receipt' => 'CONFIRMADO_04']));
        $this->assertSame([$c->pallet_code], $codes(['sapDoc' => '4711']));
        $this->assertSame([$b->pallet_code], $codes(['to' => now()->subDays(5)->format('Y-m-d')]));
        $this->assertCount(3, $codes(['physical' => 'INVENTADO', 'sapDoc' => 'abc', 'from' => '2026-02-31']));
        $this->assertSame([], $codes(['code' => '%']));
    }

    public function test_the_row_shows_description_percent_and_the_confirmed_sap_document(): void
    {
        $pallet = $this->receivedWithDocument(4711);

        Livewire::actingAs($this->viewer)->test('pages::pallets.index')
            ->assertSee($pallet->pallet_code)
            ->assertSee($pallet->item->description)
            ->assertSee('100,0')
            ->assertSee('Recibo 4711');
    }

    public function test_indicators_count_each_stage(): void
    {
        Pallet::factory()->count(2)->withBoxes(1)->create();
        Pallet::factory()->finished()->create();
        Pallet::factory()->labeled()->create();
        $this->receivedWithDocument(1);
        $pending = ProductionReceipt::factory()->create();
        $pending->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now()])->save();
        $pending->forceFill(['status' => SapDocumentStatus::PendingReconciliation])->save();

        $this->assertSame([
            'in_process' => 2, 'finished' => 3, 'labeled' => 2, 'received' => 1, 'transferred' => 0,
            'receipts_pending' => 1, 'transfers_pending' => 0, 'discrepancies_open' => 0,
        ], app(PalletBoardQuery::class)->indicators());
    }

    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->viewer)->test('pages::pallets.index');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // The first render also loads the permission cache; measure after it.
        $count();

        Pallet::factory()->count(2)->labeled()->create();
        $few = $count();

        Pallet::factory()->count(20)->labeled()->create();
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(3, $many);
    }
}
