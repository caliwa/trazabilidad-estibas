<?php

namespace Tests\Concurrency;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * The same box label read at the same moment from several stations (or a
 * reader firing many times) is counted once: the box code is the read's
 * id and the database accepts it once.
 */
class ManualBoxScanConcurrencyTest extends ConcurrencyTestCase
{
    public function test_parallel_reads_of_the_same_box_count_it_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole(Role::ProductionOperator->value);
        $ctx = TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
        app(SyncReleasedOrders::class)->handle(null, $ctx);
        $order = app(ConsultProductionOrder::class)->handle($user, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $batch = app(GenerateBoxLabels::class)->handle($user, $order->id, 3, CarbonImmutable::today(), CarbonImmutable::today()->addYear(), $ctx);
        $box = fn (int $n) => BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $batch->id)->where('sequence', $n)->value('qr_token'));

        // 10 processes, each reading box 1 twice and box 2 once.
        $summaries = $this->runInParallel(array_fill(0, 10, ['mode' => 'manual_box', 'user_id' => $user->id, 'events' => [$box(1), $box(2), $box(1)]]));

        $this->assertSame(2, $this->total($summaries, 'accepted'));
        $this->assertSame(28, $this->total($summaries, 'rejected'));
        $this->assertSame(2, PalletScanEvent::query()->count());
        $this->assertSame(2, (int) Pallet::query()->sum('boxes_count'));
    }
}
