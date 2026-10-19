<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        // Local/demo convenience only. In production create the first admin with
        // `php artisan app:create-admin`: no well-known credentials there.
        if (app()->isProduction()) {
            return;
        }

        $admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Administrador',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $admin->assignRole(Role::Admin->value);
    }
}
