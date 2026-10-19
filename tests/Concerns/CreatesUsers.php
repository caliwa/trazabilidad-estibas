<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesUsers
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(Role $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }
}
