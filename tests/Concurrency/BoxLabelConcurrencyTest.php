<?php

namespace Tests\Concurrency;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Several people generating labels for the same order at once never pass
 * what its planned quantity fills.
 */
class BoxLabelConcurrencyTest extends ConcurrencyTestCase
{
    public function test_parallel_batches_never_pass_the_planned_quantity(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole(Role::ProductionOperator->value);
        // Order 33128: 800 units, at 24 per box 34 labels at most.
        $order = app(ConsultProductionOrder::class)->handle($user, '33128', TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);

        $summaries = $this->runInParallel(array_fill(0, 12, ['mode' => 'box_labels', 'user_id' => $user->id, 'order_id' => $order->id, 'labels' => 4]));

        // 8 batches of 4 fit (32); the other 4 find only 2 labels left and are refused.
        $this->assertSame(8, $this->total($summaries, 'accepted'));
        $this->assertSame(4, $this->total($summaries, 'rejected'));
        $this->assertSame(32, (int) BoxLabelBatch::query()->sum('labels'));
    }
}
