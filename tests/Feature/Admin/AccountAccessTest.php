<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Tu cuenta está desactivada. Comunícate con el administrador.']);

        $this->assertGuest();
    }

    public function test_active_users_still_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_open_session_ends_when_the_user_is_deactivated(): void
    {
        $user = $this->userWithRole(Role::TraceabilityAdmin);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
