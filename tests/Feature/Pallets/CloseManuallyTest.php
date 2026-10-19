<?php

namespace Tests\Feature\Pallets;

use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProductionMasters;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * CA-07: manual close of an incomplete pallet.
 */
class CloseManuallyTest extends TestCase
{
    use BuildsProductionMasters, CreatesUsers, RefreshDatabase;

    private Device $device;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->enableScanIngestion();
        $this->device = Device::factory()->create();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
    }

    private function close(Pallet $pallet, ?User $user = null, ?string $reason = null): Pallet
    {
        $user ??= $this->operator;

        return app(CloseManually::class)->handle($user, $pallet->id, $reason, TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
    }

    public function test_it_closes_with_the_real_count_and_records_who_and_why(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        foreach (range(1, 7) as $i) {
            $this->process($this->device, $this->scan($order));
        }

        $pallet = $this->close(Pallet::sole(), reason: 'Fin de lote');

        $this->assertSame(PhysicalStatus::Finished, $pallet->physical_status);
        $this->assertSame(CloseType::Manual, $pallet->close_type);
        $this->assertSame(7, $pallet->boxes_count);
        $this->assertSame($this->operator->id, $pallet->closed_by);
        $this->assertSame(70.0, $pallet->completionPercent());
        $trace = TraceEvent::where('event', 'pallet.closed_manual')->sole();
        $this->assertSame($this->operator->id, $trace->user_id);
        $this->assertSame('Fin de lote', $trace->reason);
    }

    public function test_an_empty_pallet_cannot_be_closed(): void
    {
        $pallet = Pallet::factory()->create();

        $this->expectException(PalletRuleViolation::class);
        $this->close($pallet);
    }

    public function test_a_closed_pallet_cannot_be_closed_again(): void
    {
        $pallet = Pallet::factory()->finished()->create();

        $this->expectExceptionMessage('ya está cerrada');
        $this->close($pallet);
    }

    public function test_after_a_manual_close_the_next_scan_opens_a_new_pallet(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $this->process($this->device, $this->scan($order));
        $this->close(Pallet::sole());

        $result = $this->process($this->device, $this->scan($order));

        $this->assertSame(ScanOutcome::Accepted, $result->outcome);
        $this->assertSame('EST-00000002', $result->palletCode);
        $this->assertSame(1, Pallet::where('physical_status', PhysicalStatus::Finished)->sole()->boxes_count);
    }

    public function test_people_without_the_permission_are_refused_on_the_server(): void
    {
        $pallet = Pallet::factory()->withBoxes(2)->create();

        foreach ([Role::LogisticsOperator, Role::Viewer] as $role) {
            try {
                $this->close($pallet, $this->userWithRole($role));
                $this->fail("{$role->value} no debe poder cerrar estibas.");
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(PhysicalStatus::Stacking, $pallet->refresh()->physical_status);
    }
}
