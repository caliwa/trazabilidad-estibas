<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-admin {--email=} {--name=} {--with-password : Pedir la contraseña aquí en vez de enviar un enlace}')]
#[Description('Crea (o promueve) un usuario administrador. Pensado para el primer acceso en producción.')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower((string) ($this->option('email') ?: text('Correo del administrador', required: true)));
        $name = (string) ($this->option('name') ?: text('Nombre', required: true));

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;
        $user->name = $name;

        if ($this->option('with-password')) {
            $secret = password('Contraseña (mínimo 12 caracteres, mayúsculas, números y símbolos)', required: true);
            $check = Validator::make(['password' => $secret], ['password' => ['required', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]);

            if ($check->fails()) {
                $this->error($check->errors()->first('password'));

                return self::FAILURE;
            }

            $user->password = $secret;
        } elseif ($isNew) {
            $user->password = Str::password(40);
        }

        // Roles may not exist yet if db:seed was never run; the seeder is idempotent.
        DB::transaction(function () use ($user): void {
            $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'deactivated_at' => null])->save();
            $user->syncRoles([Role::Admin->value]);
        });

        if (! $this->option('with-password')) {
            Password::sendResetLink(['email' => $email]);
            $this->info("Se envió a {$email} un enlace para definir la contraseña.");
        }

        $this->info($isNew ? "Administrador {$email} creado." : "{$email} ahora es administrador.");

        return self::SUCCESS;
    }
}
