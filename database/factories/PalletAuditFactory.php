<?php

namespace Database\Factories;

use App\Domain\Audits\Enums\CountStatus;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PalletAudit>
 */
class PalletAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pallet_id' => Pallet::factory()->received(),
            'attempt' => 1,
            'operator_id' => User::factory(),
            'status' => CountStatus::Counting,
            'started_at' => now(),
        ];
    }
}
