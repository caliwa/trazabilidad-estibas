<?php

namespace Database\Seeders;

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

        // One user per role (admin@example.com, operario@example.com, ...), password "password".
        $this->call(RoleUsersSeeder::class);
    }
}
