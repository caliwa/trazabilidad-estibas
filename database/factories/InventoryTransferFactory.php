<?php

namespace Database\Factories;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Transfers\Enums\TransferMode;
use App\Models\InventoryTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryTransfer>
 */
class InventoryTransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['lines' => []];

        return [
            'request_uuid' => (string) Str::uuid(),
            'mode' => TransferMode::Individual,
            'from_warehouse' => '04',
            'to_warehouse' => '05',
            'status' => SapDocumentStatus::Requested,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'requested_by' => User::factory(),
            'requested_at' => now(),
        ];
    }
}
