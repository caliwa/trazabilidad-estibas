<?php

namespace Database\Factories;

use App\Domain\Integration\Enums\MasterStatus;
use App\Models\BoxConversionFactor;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoxConversionFactor>
 */
class BoxConversionFactorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'version' => 1,
            'units_per_box' => '12.000000',
            'sap_uom_code' => 'UND',
            'sap_source_key' => 'TEST',
            'status' => MasterStatus::Current,
            'synced_at' => now(),
        ];
    }
}
