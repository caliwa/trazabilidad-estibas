<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The administration components refuse unauthorised users by themselves,
 * without relying on the route middleware.
 */
class AdminComponentsAuthorizationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_users_component_refuses_non_admins(): void
    {
        $leader = $this->userWithRole(Role::TraceabilityAdmin);
        $victim = User::factory()->create();

        Livewire::actingAs($leader)->test('pages::admin.users')->assertForbidden();

        $this->assertTrue($victim->fresh()->isActive());
    }

    public function test_audit_component_refuses_people_without_permission(): void
    {
        Livewire::actingAs($this->userWithRole(Role::ProductionOperator))->test('pages::admin.audit')->assertForbidden();
    }
}
