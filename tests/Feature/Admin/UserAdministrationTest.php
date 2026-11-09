<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::Admin);
    }

    private function page(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test('pages::admin.users');
    }

    public function test_only_admins_manage_users(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users'))->assertOk();
        $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('admin.users'))->assertForbidden();
        $this->actingAs($this->userWithRole(Role::ProductionOperator))->get(route('admin.users'))->assertForbidden();
    }

    public function test_admin_creates_a_user_with_the_password_they_set(): void
    {
        Notification::fake();

        $this->page()
            ->call('create')
            ->set('form.name', 'Diana Salazar')
            ->set('form.email', 'Diana@Empresa.co')
            ->set('form.role', Role::LogisticsOperator->value)
            ->set('password', 'Clave-Segura-2026')
            ->set('password_confirmation', 'Clave-Segura-2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('password', '');

        $user = User::where('email', 'diana@empresa.co')->sole();
        $this->assertTrue($user->hasRole(Role::LogisticsOperator->value));
        $this->assertTrue(Hash::check('Clave-Segura-2026', $user->password));
        Notification::assertNothingSent();
        $this->assertSame('user.created', AuditLog::sole()->action);
        $this->assertSame($this->admin->id, AuditLog::sole()->user_id);
        $this->assertStringNotContainsString('Clave-Segura-2026', json_encode(AuditLog::sole()->toArray()));

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'diana@empresa.co', 'password' => 'Clave-Segura-2026'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_new_user_needs_a_confirmed_password(): void
    {
        $this->page()
            ->call('create')
            ->set('form.name', 'Diana')
            ->set('form.email', 'diana@empresa.co')
            ->call('save')
            ->assertHasErrors(['password' => 'required'])
            ->set('password', 'Clave-Segura-2026')
            ->set('password_confirmation', 'otra')
            ->call('save')
            ->assertHasErrors(['password' => 'confirmed']);

        $this->assertNull(User::where('email', 'diana@empresa.co')->first());
    }

    public function test_admin_sets_a_new_password_and_closes_the_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->userWithRole(Role::ProductionOperator);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = $user->remember_token;

        $this->page()
            ->call('askPassword', $user->id)
            ->assertSee($user->email)
            ->set('password', 'Nueva-Clave-2026')
            ->set('password_confirmation', 'Nueva-Clave-2026')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertSet('password', '');

        $user->refresh();
        $this->assertTrue(Hash::check('Nueva-Clave-2026', $user->password));
        $this->assertNotSame($token, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $log = AuditLog::query()->where('action', 'user.password_set')->sole();
        $this->assertSame([$this->admin->id, $user->id], [$log->user_id, $log->auditable_id]);
        $this->assertNull($log->new_values);
    }

    public function test_only_admins_set_passwords(): void
    {
        $user = $this->userWithRole(Role::Viewer);

        Livewire::actingAs($this->userWithRole(Role::ProductionSupervisor))->test('pages::admin.users')->assertForbidden();
        $this->page()->call('askPassword', $user->id)->set('password', 'corta')->set('password_confirmation', 'corta')->call('savePassword')->assertHasErrors(['password']);
    }

    public function test_validation(): void
    {
        $this->page()
            ->call('create')
            ->set('form.name', '')
            ->set('form.email', $this->admin->email)
            ->set('form.role', 'superuser')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.email' => 'unique', 'form.role']);
    }

    public function test_role_changes_are_audited(): void
    {
        $user = $this->userWithRole(Role::ProductionOperator);

        $this->page()
            ->call('edit', $user->id)
            ->assertSet('form.role', Role::ProductionOperator->value)
            ->set('form.role', Role::TraceabilityAdmin->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($user->fresh()->hasRole(Role::TraceabilityAdmin->value));
        $log = AuditLog::where('action', 'user.role_changed')->sole();
        $this->assertSame(['role' => 'production_operator'], $log->old_values);
        $this->assertSame(['role' => 'traceability_admin'], $log->new_values);
    }

    public function test_admins_cannot_lock_themselves_out(): void
    {
        $this->page()
            ->call('edit', $this->admin->id)
            ->set('form.role', Role::Viewer->value)
            ->call('save')
            ->assertHasErrors(['form.role']);

        $this->page()->call('toggleActive', $this->admin->id)->assertHasErrors(['user']);

        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin->value));
        $this->assertTrue($this->admin->fresh()->isActive());
    }

    public function test_deactivation_is_audited_and_reversible(): void
    {
        $worker = $this->userWithRole(Role::ProductionOperator);

        $this->page()->call('toggleActive', $worker->id)->assertHasNoErrors();

        $this->assertFalse($worker->fresh()->isActive());
        $this->assertSame('user.deactivated', AuditLog::sole()->action);

        $this->page()->set('status', 'inactive')->assertSee($worker->email);
        $this->page()->call('toggleActive', $worker->id);
        $this->assertTrue($worker->fresh()->isActive());
    }

    public function test_new_users_and_users_without_a_role_default_to_the_least_privileged_one(): void
    {
        $this->page()->call('create')->assertSet('form.role', Role::Viewer->value);

        $roleless = User::factory()->create();
        $this->page()->call('edit', $roleless->id)->assertSet('form.role', Role::Viewer->value);
    }
}
