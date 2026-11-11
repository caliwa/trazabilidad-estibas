<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * One user per role, to check what each role sees and can do.
 *
 *   php artisan db:seed --class=RoleUsersSeeder
 *
 * The password comes from ROLE_USERS_PASSWORD. Outside production it
 * defaults to "password"; in production it is required and must meet the
 * password policy, so no well-known password ends up there.
 *
 * Existing users with these emails are left as they are (password and role).
 */
class RoleUsersSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> role => [email, name] */
    public const USERS = [
        'admin' => ['admin@example.com', 'Administrador'],
        'traceability_admin' => ['trazabilidad@example.com', 'Administrador de trazabilidad'],
        'production_supervisor' => ['supervisor.produccion@example.com', 'Supervisor de producción'],
        'quality' => ['calidad@example.com', 'Calidad'],
        'production_operator' => ['operario@example.com', 'Operario de producción'],
        'production_skater' => ['patinador@example.com', 'Patinador de producción'],
        'logistics_operator' => ['logistica@example.com', 'Operario logístico'],
        'logistics_coordinator' => ['coordinador.logistica@example.com', 'Coordinador logístico'],
        'viewer' => ['consulta@example.com', 'Consulta'],
    ];

    public function run(): void
    {
        $password = (string) config('traceability.role_users_password');
        if ($password === '' && ! app()->isProduction()) {
            $password = 'password';
        }

        if (app()->isProduction()) {
            $check = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

            if ($check->fails()) {
                $this->command->error('En producción define ROLE_USERS_PASSWORD con una contraseña que cumpla la política (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos). No se creó ningún usuario.');

                return;
            }
        }

        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function () use ($password): void {
            foreach (Role::cases() as $role) {
                [$email, $name] = self::USERS[$role->value];
                $user = User::query()->where('email', $email)->first();

                if ($user !== null) {
                    $this->command->line("  {$email} ya existe: no se modificó.");

                    continue;
                }

                $user = new User(['name' => $name, 'email' => $email]);
                $user->forceFill(['password' => $password, 'email_verified_at' => now()])->save();
                $user->assignRole($role->value);
                $this->command->line("  {$email} · {$role->label()}");
            }
        });
    }
}
