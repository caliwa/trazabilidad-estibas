<?php

namespace Tests\Feature\Pallets;

use App\Domain\Pallets\Actions\ReprocessRejectedEvent;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Data\ScanResult;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProductionMasters;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReprocessRejectedEventTest extends TestCase
{
    use BuildsProductionMasters, CreatesUsers, RefreshDatabase;

    private function reprocess(User $user, PalletScanEvent $event): ScanResult
    {
        return app(ReprocessRejectedEvent::class)->handle($user, $event->id, TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
    }

    public function test_a_rejected_event_is_accepted_once_after_fixing_the_master(): void
    {
        $this->seedRoles();
        $this->enableScanIngestion();
        $admin = $this->userWithRole(Role::TraceabilityAdmin);
        $order = ProductionOrder::factory()->create();
        $device = Device::factory()->create();
        // Microseconds and a non-local time zone: the rebuilt event must hash exactly like the original.
        $this->process($device, $this->scan($order, eventId: 'E-1', at: CarbonImmutable::parse('2026-10-19T15:00:00.123456Z')));
        $event = PalletScanEvent::sole();

        $this->assertSame(ScanOutcome::Rejected, $this->reprocess($admin, $event)->outcome);

        PalletStandard::factory()->for($order->item)->boxes(5)->create();
        $result = $this->reprocess($admin, $event);

        $this->assertSame(ScanOutcome::Accepted, $result->outcome);
        $this->assertSame(1, Pallet::sole()->boxes_count);

        $this->expectException(PalletRuleViolation::class);
        $this->reprocess($admin, $event->refresh());
    }

    public function test_reprocessing_needs_its_permission(): void
    {
        $this->seedRoles();
        $event = PalletScanEvent::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->reprocess($this->userWithRole(Role::ProductionOperator), $event);
    }
}
