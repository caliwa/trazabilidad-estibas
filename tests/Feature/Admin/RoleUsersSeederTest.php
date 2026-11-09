<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_user_per_role_that_can_sign_in(): void
    {
        $this->seed(RoleUsersSeeder::class);

        foreach (Role::cases() as $role) {
            [$email] = RoleUsersSeeder::USERS[$role->value];
            $user = User::query()->where('email', $email)->sole();
            $this->assertSame([$role->value], $user->getRoleNames()->all(), $email);
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertNotNull($user->email_verified_at);
        }

        $this->post(route('login.store'), ['email' => 'operario@example.com', 'password' => 'password']);
        $this->get(route('production.station'))->assertOk();
        $this->get(route('admin.users'))->assertForbidden();
    }

    public function test_it_uses_the_configured_password_and_leaves_existing_users_alone(): void
    {
        $existing = User::factory()->create(['email' => 'calidad@example.com', 'password' => 'otra-clave']);
        config(['traceability.role_users_password' => 'Clave-De-Prueba-2026']);

        $this->seed(RoleUsersSeeder::class);
        $this->seed(RoleUsersSeeder::class);

        $this->assertSame(7, User::query()->count());
        $this->assertTrue(Hash::check('otra-clave', $existing->refresh()->password));
        $this->assertSame([], $existing->getRoleNames()->all());
        $this->assertTrue(Hash::check('Clave-De-Prueba-2026', User::query()->where('email', 'consulta@example.com')->value('password')));
    }

    public function test_in_production_it_needs_a_strong_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['traceability.role_users_password' => null]);

        // Without the console: db:seed would ask to confirm in production.
        $this->app->make(RoleUsersSeeder::class)->__invoke();

        $this->assertSame(0, User::query()->count());
    }
}
