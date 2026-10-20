<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_the_logistics_operator_cannot_see_pallet_quantities_anywhere(): void
    {
        $operator = $this->userWithRole(Role::LogisticsOperator);

        $this->assertTrue($operator->can(Permission::AuditsCount->value));
        // The board shows box counts; seeing it would break the blind count.
        $this->assertFalse($operator->can(Permission::PalletsView->value));
        $this->assertFalse($operator->can(Permission::TraceView->value));
        $this->assertFalse($operator->can(Permission::DiscrepanciesResolve->value));
    }

    public function test_operators_do_not_move_sap_documents(): void
    {
        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Viewer] as $role) {
            $user = $this->userWithRole($role);

            foreach ([Permission::ReceiptsRequest, Permission::ReceiptsReconcile, Permission::TransfersReconcile] as $permission) {
                $this->assertFalse($user->can($permission->value), "{$role->value} no debe tener {$permission->value}");
            }
        }
    }

    public function test_the_viewer_only_reads(): void
    {
        $viewer = $this->userWithRole(Role::Viewer);

        $this->assertSame(
            [Permission::PalletsView->value, Permission::TraceView->value],
            $viewer->getAllPermissions()->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_the_seeder_is_idempotent_and_syncs_every_permission(): void
    {
        $this->seedRoles();

        $this->assertDatabaseCount('permissions', count(Permission::cases()));
        $this->assertDatabaseCount('roles', count(Role::cases()));
        $this->assertTrue($this->userWithRole(Role::Admin)->can(Permission::DevicesManage->value));
    }

    public function test_the_seeder_removes_permissions_that_no_longer_exist(): void
    {
        $this->seedRoles();
        $user = $this->userWithRole(Role::Viewer);
        $user->givePermissionTo(PermissionModel::findOrCreate('transfers.request', 'web'));

        $this->seedRoles();

        $this->assertDatabaseMissing('permissions', ['name' => 'transfers.request']);
        $this->assertFalse($user->fresh()?->getAllPermissions()->contains('name', 'transfers.request'));
    }
}
