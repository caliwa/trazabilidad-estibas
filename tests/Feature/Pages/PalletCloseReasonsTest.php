<?php

namespace Tests\Feature\Pages;

use App\Actions\Pallets\ManagePalletCloseReasons;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PalletCloseReason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PalletCloseReasonsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_supervisors_and_admins_manage_the_list_other_roles_do_not(): void
    {
        foreach ([Role::Admin, Role::ProductionSupervisor] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('admin.close-reasons'))->assertOk()->assertSee('Motivos de cierre');
        }

        foreach ([Role::TraceabilityAdmin, Role::ProductionOperator, Role::Quality, Role::LogisticsOperator, Role::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('admin.close-reasons'))->assertForbidden();
            Livewire::actingAs($user)->test('pages::admin.close-reasons')->assertForbidden();
        }

        $this->expectException(AuthorizationException::class);
        app(ManagePalletCloseReasons::class)->create($this->userWithRole(Role::ProductionOperator), ['name' => 'X', 'sort_order' => 0]);
    }

    public function test_create_edit_and_deactivate_a_reason_with_audit(): void
    {
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        $page = Livewire::actingAs($supervisor)->test('pages::admin.close-reasons')
            ->assertSee('No hay motivos activos')
            ->call('create')
            ->set('form.name', '  Fin   de turno ')
            ->call('save')
            ->assertHasNoErrors();

        $reason = PalletCloseReason::query()->sole();
        $this->assertSame('Fin de turno', $reason->name);
        $this->assertSame(10, $reason->sort_order);
        $this->assertTrue($reason->active);

        $page->call('create')->set('form.name', 'FIN DE TURNO')->call('save')->assertHasErrors(['form.name']);
        $this->assertSame(1, PalletCloseReason::query()->count());

        $page->call('edit', $reason->id)->set('form.name', 'Cambio de turno')->set('form.sort_order', 5)->call('save')->assertHasNoErrors();
        $page->call('toggleActive', $reason->id)->assertSee('Inactivo');
        $this->assertFalse($reason->refresh()->active);
        $this->assertSame('Cambio de turno', $reason->name);

        $this->assertSame(
            ['pallet_close_reason.created', 'pallet_close_reason.updated', 'pallet_close_reason.deactivated'],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame($supervisor->id, AuditLog::query()->latest('id')->value('user_id'));
    }

    public function test_the_name_is_required(): void
    {
        Livewire::actingAs($this->userWithRole(Role::Admin))->test('pages::admin.close-reasons')
            ->call('create')->set('form.name', '')->call('save')->assertHasErrors(['form.name' => 'required']);
    }
}
