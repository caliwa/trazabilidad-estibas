<?php

namespace Database\Factories;

use App\Domain\Integration\Enums\MasterStatus;
use App\Models\Item;
use App\Models\PalletStandard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PalletStandard>
 */
class PalletStandardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'version' => 1,
            'boxes_per_pallet' => 40,
            'sap_source_key' => 'TEST',
            'status' => MasterStatus::Current,
            'synced_at' => now(),
        ];
    }

    public function boxes(int $boxes): static
    {
        return $this->state(['boxes_per_pallet' => $boxes]);
    }
}
