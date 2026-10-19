<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ProductionSetupTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_seeder_creates_no_default_admin_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertDatabaseCount('roles', 5);
    }

    public function test_create_admin_command_sends_a_password_link(): void
    {
        Notification::fake();
        $this->seedRoles();

        $this->artisan('app:create-admin', ['--email' => 'TI@Empresa.co', '--name' => 'Mesa TI'])->assertSuccessful();

        $admin = User::where('email', 'ti@empresa.co')->sole();
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        Notification::assertSentTo($admin, ResetPassword::class);
    }

    public function test_create_admin_command_works_before_roles_are_seeded(): void
    {
        Notification::fake();
        $this->assertDatabaseCount('roles', 0);

        $this->artisan('app:create-admin', ['--email' => 'ti@empresa.co', '--name' => 'Mesa TI'])->assertSuccessful();

        $this->assertDatabaseCount('roles', count(Role::cases()));
        $this->assertTrue(User::where('email', 'ti@empresa.co')->sole()->hasRole(Role::Admin->value));
    }

    public function test_create_admin_command_can_ask_for_a_strong_password(): void
    {
        $this->seedRoles();

        $this->artisan('app:create-admin', ['--email' => 'ti@empresa.co', '--name' => 'Mesa TI', '--with-password' => true])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, números y símbolos)', 'corta')
            ->assertFailed();

        $this->artisan('app:create-admin', ['--email' => 'ti@empresa.co', '--name' => 'Mesa TI', '--with-password' => true])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, números y símbolos)', 'Clave-Segura-2026!')
            ->assertSuccessful();

        $this->post(route('login.store'), ['email' => 'ti@empresa.co', 'password' => 'Clave-Segura-2026!'])->assertSessionHasNoErrors();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
