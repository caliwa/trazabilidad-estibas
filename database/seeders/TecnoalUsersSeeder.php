<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder para usuarios de planta Tecnoal:
 *   - liz.garcia (liz.garcia@tecnoalsa.com)
 *   - sebastian.henao (sebastian.henao@tecnoalsa.com)
 *   - sandra.ines (sandra.ines@tecnoalsa.com)
 *   - edison.garcia (edison.garcia@tecnoalsa.com)
 *
 * Contraseña asignada para todos: tecnoal123
 *
 * Ejecutar con:
 *   php artisan db:seed --class=TecnoalUsersSeeder --force
 */
class TecnoalUsersSeeder extends Seeder
{
    public const PASSWORD = 'tecnoal123';

    /**
     * @var list<array{name: string, email: string, role: string}>
     */
    public const USERS = [
        [
            'name' => 'liz.garcia',
            'email' => 'liz.garcia@tecnoalsa.com',
            'role' => Role::ProductionSupervisor->value,
        ],
        [
            'name' => 'sebastian.henao',
            'email' => 'sebastian.henao@tecnoalsa.com',
            'role' => Role::ProductionSupervisor->value,
        ],
        [
            'name' => 'sandra.ines',
            'email' => 'sandra.ines@tecnoalsa.com',
            'role' => Role::ProductionSupervisor->value,
        ],
        [
            'name' => 'edison.garcia',
            'email' => 'edison.garcia@tecnoalsa.com',
            'role' => Role::ProductionSupervisor->value,
        ],
    ];

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function (): void {
            $hashedPassword = Hash::make(self::PASSWORD);

            foreach (self::USERS as $userData) {
                $user = User::query()
                    ->where('email', $userData['email'])
                    ->orWhere('name', $userData['name'])
                    ->first();

                if ($user !== null) {
                    $user->forceFill([
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                        'password' => $hashedPassword,
                        'email_verified_at' => $user->email_verified_at ?? now(),
                        'deactivated_at' => null,
                    ])->save();

                    if (! $user->hasRole($userData['role'])) {
                        $user->assignRole($userData['role']);
                    }

                    $this->command->info("✓ Usuario {$userData['name']} ({$userData['email']}) actualizado con contraseña ".self::PASSWORD.'.');
                } else {
                    $user = new User([
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                    ]);
                    $user->forceFill([
                        'password' => $hashedPassword,
                        'email_verified_at' => now(),
                        'deactivated_at' => null,
                    ])->save();

                    $user->assignRole($userData['role']);

                    $this->command->info("✓ Usuario {$userData['name']} ({$userData['email']}) creado con rol {$userData['role']} y contraseña ".self::PASSWORD.'.');
                }
            }
        });
    }
}
