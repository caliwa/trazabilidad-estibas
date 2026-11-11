<?php

namespace Tests\Feature\Pages;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The logistics coordinator role and the logistics home board (transfers
 * and counts, in pallets only).
 */
class LogisticsRoleAndBoardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->coordinator = $this->userWithRole(Role::LogisticsCoordinator);
    }

    public function test_the_coordinator_counts_and_follows_the_of_board_and_the_pallets_and_the_operator_still_does_not(): void
    {
        $this->assertSame('Coordinador logístico', Role::LogisticsCoordinator->label());

        foreach (['cedi.count', 'orders.index', 'pallets.index', 'pallets.rejected'] as $route) {
            $this->actingAs($this->coordinator)->get(route($route))->assertOk();
        }
        $pallet = Pallet::factory()->create();
        $this->actingAs($this->coordinator)->get(route('pallets.show', $pallet))->assertOk();

        foreach (['production.station', 'receipts.index', 'admin.users', 'discrepancies.index'] as $route) {
            $this->actingAs($this->coordinator)->get(route($route))->assertForbidden();
        }

        $operator = $this->userWithRole(Role::LogisticsOperator);
        $this->actingAs($operator)->get(route('cedi.count'))->assertOk();
        $this->actingAs($operator)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('pallets.index'))->assertForbidden();
    }

    public function test_the_logistics_home_shows_transfers_and_counts_in_pallets_without_boxes(): void
    {
        Pallet::factory()->approved()->create(['boxes_count' => 37]);
        Pallet::factory()->received()->create();
        $failed = Pallet::factory()->approved()->create();
        $failed->forceFill(['transfer_status' => TransferStatus::Requested])->save();
        $failed->forceFill(['transfer_status' => TransferStatus::Failed])->save();

        foreach ([Role::LogisticsOperator, Role::LogisticsCoordinator] as $role) {
            Livewire::actingAs($this->userWithRole($role))->test('pages::dashboard')
                ->assertSeeHtml('data-logistics-board')
                ->assertSee('Traslados a CEDI (04 → 05)')
                ->assertSee('Trasladadas hoy')
                ->assertSee('1 listas')
                ->assertSee('Traslado fallido')
                ->assertSee('Esperando conteo')
                ->assertSee('Discrepancias abiertas')
                ->assertSee('Estibas trasladadas a CEDI, últimos 7 días')
                ->assertDontSee('cajas')
                ->assertDontSeeHtml('data-operator-board');
        }

        $this->assertSame(AuditStatus::Pending, Pallet::query()->where('receipt_status', 'CONFIRMADO_04')->where('audit_status', 'PENDIENTE')->firstOrFail()->audit_status);

        foreach ([Role::ProductionOperator, Role::ProductionSupervisor, Role::Viewer] as $role) {
            Livewire::actingAs($this->userWithRole($role))->test('pages::dashboard')->assertDontSeeHtml('data-logistics-board');
        }
    }
}
