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
use App\Models\PalletCloseReason;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private PalletCloseReason $reason;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->enableScanIngestion();
        $this->device = Device::factory()->create();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        $this->reason = PalletCloseReason::query()->create(['name' => 'Fin de lote']);
    }

    private function close(Pallet $pallet, ?User $user = null, ?string $note = null, ?PalletCloseReason $reason = null): Pallet
    {
        $user ??= $this->operator;

        return app(CloseManually::class)->handle($user, $pallet->id, ($reason ?? $this->reason)->id, $note, TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
    }

    public function test_it_closes_with_the_real_count_and_records_who_and_why(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        foreach (range(1, 7) as $i) {
            $this->process($this->device, $this->scan($order));
        }

        $pallet = $this->close(Pallet::sole(), note: '  falta material  ');

        $this->assertSame(PhysicalStatus::Finished, $pallet->physical_status);
        $this->assertSame(CloseType::Manual, $pallet->close_type);
        $this->assertSame(7, $pallet->boxes_count);
        $this->assertSame($this->operator->id, $pallet->closed_by);
        $this->assertSame(70.0, $pallet->completionPercent());
        $trace = TraceEvent::where('event', 'pallet.closed_manual')->sole();
        $this->assertSame($this->operator->id, $trace->user_id);
        $this->assertSame('Fin de lote: falta material', $trace->reason);
        $this->assertSame('Fin de lote', $trace->context['close_reason']);
        $this->assertSame($this->reason->id, $pallet->close_reason_id);
        $this->assertSame('falta material', $pallet->close_note);
    }

    public function test_without_a_note_the_trace_keeps_only_the_reason(): void
    {
        $pallet = $this->close(Pallet::factory()->withBoxes(2)->create());

        $this->assertNull($pallet->close_note);
        $this->assertSame('Fin de lote', TraceEvent::where('event', 'pallet.closed_manual')->sole()->reason);
    }

    public function test_an_inactive_or_unknown_reason_is_refused(): void
    {
        $pallet = Pallet::factory()->withBoxes(2)->create();
        $inactive = PalletCloseReason::query()->create(['name' => 'Viejo', 'active' => false]);

        foreach ([$inactive, (new PalletCloseReason(['name' => 'x']))->forceFill(['id' => 999_999])] as $reason) {
            try {
                $this->close($pallet, reason: $reason);
                $this->fail('Debe pedir un motivo activo.');
            } catch (PalletRuleViolation $e) {
                $this->assertStringContainsString('motivo de cierre', $e->getMessage());
            }
        }

        $this->assertSame(PhysicalStatus::Stacking, $pallet->refresh()->physical_status);
    }

    public function test_the_database_refuses_a_manual_close_without_reason_and_keeps_the_recorded_one(): void
    {
        $pallet = Pallet::factory()->withBoxes(2)->create();

        try {
            DB::transaction(fn () => $pallet->forceFill(['physical_status' => PhysicalStatus::Finished, 'close_type' => CloseType::Manual, 'finished_at' => now(), 'closed_by' => $this->operator->id])->save());
            $this->fail('El cierre manual sin motivo debe fallar en la base de datos.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('necesita un motivo', $e->getMessage());
        }

        $closed = $this->close($pallet->refresh());
        $other = PalletCloseReason::query()->create(['name' => 'Otro']);

        $this->expectExceptionMessage('ya quedó registrado');
        $closed->forceFill(['close_reason_id' => $other->id])->save();
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
