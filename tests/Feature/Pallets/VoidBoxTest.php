<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Actions\VoidBox;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\BoxVoid;
use App\Models\BoxVoidReason;
use App\Models\Pallet;
use App\Models\PalletCloseReason;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Voiding a box already counted: the pallet loses it, a full pallet opens
 * again, the box never counts again and everything is traced.
 */
class VoidBoxTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private BoxLabelBatch $batch;

    private BoxVoidReason $damaged;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        config(['traceability.test_pallet_boxes' => 3, 'traceability.decisions.scan_ingestion_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        app(SyncReleasedOrders::class)->handle(null, $this->ctx());
        $order = app(ConsultProductionOrder::class)->handle($this->operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $order->id, 10, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $this->ctx());
        $this->damaged = BoxVoidReason::query()->create(['name' => 'Caja averiada']);
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    private function box(int $n): string
    {
        return BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->value('qr_token'));
    }

    private function read(int $n): void
    {
        $this->travel(2)->seconds();
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->box($n), $this->ctx());
    }

    private function void(int $n, ?string $note = null, ?User $user = null): BoxVoid
    {
        $this->travel(2)->seconds();

        return app(VoidBox::class)->handle($user ?? $this->operator, $this->box($n), $this->damaged->id, $note, $this->ctx($user));
    }

    private function refused(int $n, string $message): void
    {
        try {
            $this->void($n);
            $this->fail('Debía rechazarse: '.$message);
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_voiding_a_box_of_an_open_pallet_takes_one_box_off_and_traces_it(): void
    {
        $this->read(1);
        $this->read(2);

        $void = $this->void(2, 'golpe en la esquina');

        $pallet = Pallet::sole();
        $this->assertSame([1, PhysicalStatus::Stacking], [$pallet->boxes_count, $pallet->physical_status]);
        $this->assertSame('33128293-0002', $void->box_code);
        $this->assertSame(['boxes' => 2, 'physical_status' => 'EN_ESTIBADO', 'close_type' => null], $void->pallet_before);
        $this->assertSame(['boxes' => 1, 'physical_status' => 'EN_ESTIBADO', 'close_type' => null], $void->pallet_after);
        $this->assertSame($this->operator->id, $void->voided_by);

        $trace = TraceEvent::query()->where('event', 'box.voided')->sole();
        $this->assertSame('Caja 33128293-0002: Caja averiada: golpe en la esquina', $trace->reason);
        $this->assertSame($this->operator->id, $trace->user_id);
        $this->assertSame($pallet->id, $trace->subject_id);
        $this->assertSame(1, PalletScanEvent::query()->counted()->count());
        $this->assertSame(2, PalletScanEvent::query()->count(), 'La lectura original queda registrada.');
    }

    public function test_a_voided_box_cannot_be_read_or_voided_again(): void
    {
        $this->read(1);
        $this->void(1);

        try {
            $this->read(1);
            $this->fail('Una caja anulada no se vuelve a contar.');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('ya fue anulada', $e->getMessage());
        }

        $this->refused(1, 'ya fue anulada');
        $this->assertSame(0, Pallet::sole()->boxes_count);
    }

    public function test_voiding_a_box_of_a_full_pallet_reopens_it_and_the_next_box_goes_there(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->read($n);
        }
        $pallet = Pallet::sole();
        $this->assertSame([PhysicalStatus::Finished, CloseType::Automatic], [$pallet->physical_status, $pallet->close_type]);

        $this->void(2);

        $pallet->refresh();
        $this->assertSame([2, PhysicalStatus::Stacking, null, null], [$pallet->boxes_count, $pallet->physical_status, $pallet->close_type, $pallet->finished_at]);
        $this->assertSame(1, TraceEvent::query()->where('event', 'pallet.reopened')->count());

        $this->read(4);
        $this->assertSame(1, Pallet::query()->count(), 'La siguiente caja completa la estiba reabierta.');
        $this->assertSame([3, PhysicalStatus::Finished], [$pallet->refresh()->boxes_count, $pallet->physical_status]);
    }

    public function test_a_full_pallet_stays_closed_as_incomplete_when_the_line_already_opened_the_next(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $this->read($n);
        }
        [$first, $second] = Pallet::query()->orderBy('id')->get()->all();
        $this->assertSame(PhysicalStatus::Stacking, $second->physical_status);

        $void = $this->void(3);

        $first->refresh();
        $this->assertSame([2, PhysicalStatus::Finished, CloseType::Manual, $this->operator->id], [$first->boxes_count, $first->physical_status, $first->close_type, $first->closed_by]);
        $this->assertSame('MANUAL', $void->pallet_after['close_type']);
        $this->assertSame(0, TraceEvent::query()->where('event', 'pallet.reopened')->count());
        $this->assertSame($second->pallet_code, TraceEvent::query()->where('event', 'box.voided')->sole()->context['other_open_pallet']);
    }

    public function test_a_pallet_closed_by_hand_stays_closed_and_reopens_only_when_left_empty(): void
    {
        $this->read(1);
        $this->read(2);
        $pallet = Pallet::sole();
        $close = PalletCloseReason::query()->create(['name' => 'Fin de turno']);
        app(CloseManually::class)->handle($this->operator, $pallet->id, $close->id, null, $this->ctx());

        $this->void(1);
        $pallet->refresh();
        $this->assertSame([1, PhysicalStatus::Finished, CloseType::Manual, $close->id], [$pallet->boxes_count, $pallet->physical_status, $pallet->close_type, $pallet->close_reason_id]);

        $this->void(2);
        $pallet->refresh();
        $this->assertSame([0, PhysicalStatus::Stacking, null, null], [$pallet->boxes_count, $pallet->physical_status, $pallet->close_type, $pallet->close_reason_id]);
    }

    public function test_boxes_of_a_palletized_pallet_cannot_be_voided(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->read($n);
        }
        app(ConfirmPalletized::class)->handle($this->operator, Pallet::sole()->id, $this->ctx());

        $this->refused(2, 'ya fue paletizada');
        $this->assertSame(3, Pallet::sole()->boxes_count);
    }

    public function test_a_box_never_read_or_an_inactive_reason_is_refused(): void
    {
        $this->refused(5, 'no tiene una lectura contada');

        $this->read(1);
        $this->damaged->forceFill(['active' => false])->save();
        $this->refused(1, 'motivo de anulación');
        $this->assertSame(1, Pallet::sole()->boxes_count);
    }

    public function test_only_people_with_the_permission_void_boxes(): void
    {
        $this->read(1);

        foreach ([Role::ProductionSupervisor, Role::Quality, Role::LogisticsOperator, Role::Viewer] as $role) {
            try {
                $this->void(1, user: $this->userWithRole($role));
                $this->fail("{$role->value} no debe poder anular cajas.");
            } catch (AuthorizationException) {
            }
        }

        $this->void(1, user: $this->userWithRole(Role::TraceabilityAdmin));
        $this->assertSame(0, Pallet::sole()->boxes_count);
    }

    public function test_the_database_only_lets_the_count_go_down_with_a_void_record(): void
    {
        $this->read(1);
        $this->read(2);
        $pallet = Pallet::sole();

        try {
            DB::transaction(fn () => Pallet::query()->whereKey($pallet->id)->update(['boxes_count' => 1]));
            $this->fail('Sin anulación registrada la cantidad no baja.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no puede disminuir', $e->getMessage());
        }

        $void = $this->void(1);

        $this->expectExceptionMessage('append_only');
        BoxVoid::query()->whereKey($void->id)->update(['note' => 'cambiada']);
    }

    public function test_the_station_finds_the_box_explains_and_voids_it(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->read($n);
        }

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('Anular caja leída')
            ->call('askVoid')
            ->set('voidQr', $this->box(9))
            ->call('findVoidBox')
            ->assertSet('voidBox', null)
            ->assertSee('no tiene una lectura contada')
            ->set('voidQr', $this->box(2))
            ->call('findVoidBox')
            ->assertSee('33128293-0002')
            ->assertSee('se reabre con 2 cajas')
            ->assertSee('Caja averiada')
            ->call('confirmVoid')
            ->assertHasErrors(['voidReasonId' => 'required'])
            ->set('voidReasonId', (string) $this->damaged->id)
            ->call('confirmVoid')
            ->assertHasNoErrors()
            ->assertSet('voidBox', null);

        $this->assertSame([2, PhysicalStatus::Stacking], [Pallet::sole()->boxes_count, Pallet::sole()->physical_status]);
        $this->assertSame('33128293-0002', BoxVoid::sole()->box_code);
    }

    public function test_the_void_button_shows_only_with_the_permission(): void
    {
        $onlyOperates = User::factory()->create()->givePermissionTo('pallets.operate');
        Livewire::actingAs($onlyOperates)->test('pages::production.station')->assertDontSee('Anular caja leída');
        Livewire::actingAs($onlyOperates)->test('pages::production.station')->call('askVoid')->assertForbidden();
    }
}
