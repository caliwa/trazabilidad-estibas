<?php

namespace Tests\Feature\Schema;

use App\Domain\Integration\Enums\MasterStatus;
use App\Models\BoxConversionFactor;
use App\Models\Item;
use App\Models\PalletStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

/**
 * RN-04: the standard and the conversion factor are positive and there is
 * one current version per item.
 */
class MasterInvariantsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    public function test_one_current_standard_per_item(): void
    {
        $item = Item::factory()->create();
        PalletStandard::factory()->for($item)->create(['version' => 1]);

        $this->assertDatabaseRejects(fn () => PalletStandard::factory()->for($item)->create(['version' => 2]), 'pallet_standards_one_current');

        PalletStandard::factory()->for($item)->create(['version' => 3, 'status' => MasterStatus::Superseded]);
        $this->assertSame(2, PalletStandard::count());
    }

    public function test_current_values_must_be_positive_and_invalid_rows_say_why(): void
    {
        $this->assertDatabaseRejects(fn () => PalletStandard::factory()->create(['boxes_per_pallet' => 0]), 'pallet_standards_value_check');
        $this->assertDatabaseRejects(fn () => BoxConversionFactor::factory()->create(['units_per_box' => '0']), 'box_conversion_factors_value_check');
        $this->assertDatabaseRejects(fn () => PalletStandard::factory()->create(['status' => MasterStatus::Invalid, 'boxes_per_pallet' => null]), 'pallet_standards_invalid_reason_check');

        PalletStandard::factory()->create(['status' => MasterStatus::Invalid, 'boxes_per_pallet' => null, 'invalid_reason' => 'SAP envió el campo vacío']);
        $this->assertSame(1, PalletStandard::count());
    }

    public function test_validity_window_must_be_ordered(): void
    {
        $this->assertDatabaseRejects(
            fn () => PalletStandard::factory()->create(['valid_from' => now(), 'valid_to' => now()->subDay()]),
            'pallet_standards_validity_check',
        );
    }
}
