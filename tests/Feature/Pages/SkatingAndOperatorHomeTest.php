<?php

namespace Tests\Feature\Pages;

use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The production skater's page (Patinaje) and the operator's home board.
 */
class SkatingAndOperatorHomeTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $skater;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['traceability.decisions.label_confirmation' => 'button']);
        $this->skater = $this->userWithRole(Role::ProductionSkater);
    }

    public function test_only_the_skater_and_the_admin_open_patinaje_and_the_skater_does_not_operate_the_station(): void
    {
        $this->actingAs($this->skater)->get(route('production.skating'))->assertOk()->assertSee('Patinaje');
        $this->actingAs($this->userWithRole(Role::Admin))->get(route('production.skating'))->assertOk();
        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Viewer, Role::Quality] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('production.skating'))->assertForbidden();
        }

        $this->actingAs($this->skater)->get(route('production.station'))->assertForbidden();
        $this->assertSame('Patinador de producción', Role::ProductionSkater->label());
        $this->actingAs($this->skater)->get(route('receipts.index'))->assertOk();
        $this->actingAs($this->skater)->get(route('pallets.index'))->assertOk();
    }

    public function test_the_skater_sees_the_line_and_palletizes_labels_and_confirms_closed_pallets(): void
    {
        $open = Pallet::factory()->withBoxes(12)->create(['pallet_code' => 'EST-00000077']);
        $closed = Pallet::factory()->finished()->create(['pallet_code' => 'EST-00000078']);

        $page = Livewire::actingAs($this->skater)->test('pages::production.skating')
            ->assertSee('EST-00000077')
            ->assertSee('En estibado (solo consulta)')
            ->assertDontSee('Cerrar estiba incompleta')
            ->assertSee('EST-00000078')
            ->assertSee('Por paletizar')
            ->call('palletize', $closed->id);
        $this->assertSame(PhysicalStatus::Palletized, $closed->refresh()->physical_status);

        $page->call('printLabel', $closed->id)->assertRedirect(route('pallets.label', ['pallet' => $closed->id, 'print' => 1]));
        $this->assertSame(1, $closed->refresh()->label_print_count);

        $page = Livewire::actingAs($this->skater)->test('pages::production.skating')
            ->assertSee('Etiqueta impresa, por confirmar')
            ->call('askReprint', $closed->id)->set('reprintReason', '')->call('reprint')->assertHasErrors(['reprintReason' => 'required']);
        $page->call('askConfirmLabel', $closed->id)->call('confirmLabel');
        $this->assertSame(PhysicalStatus::Labeled, $closed->refresh()->physical_status);

        Livewire::actingAs($this->skater)->test('pages::production.skating')
            ->assertSee('Listas para recibo de producción')
            ->assertSee('Generar recibo')
            ->assertSeeHtml(route('receipts.index', ['orderId' => $closed->production_order_id]));

        $this->assertSame(PhysicalStatus::Stacking, $open->refresh()->physical_status);
    }

    public function test_the_operator_home_shows_reads_and_pallet_progress_and_others_do_not(): void
    {
        Pallet::factory()->withBoxes(30)->create(['pallet_code' => 'EST-00000042']);
        Pallet::factory()->finished()->create();

        Livewire::actingAs($this->userWithRole(Role::ProductionOperator))->test('pages::dashboard')
            ->assertSeeHtml('data-operator-board')
            ->assertSee('Cajas contadas hoy')
            ->assertSee('No contadas hoy')
            ->assertSee('Avance de las estibas')
            ->assertSee('EST-00000042')
            ->assertSee('Faltan 10 cajas')
            ->assertSee('1 por paletizar');

        foreach ([Role::ProductionSupervisor, Role::Viewer, Role::ProductionSkater] as $role) {
            Livewire::actingAs($this->userWithRole($role))->test('pages::dashboard')->assertDontSeeHtml('data-operator-board');
        }
    }
}
