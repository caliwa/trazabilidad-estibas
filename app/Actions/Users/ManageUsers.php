<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User administration. Accounts are created without a usable password:
 * the person receives a link to set their own. Users are deactivated,
 * never deleted, so the pallets, counts and documents they touched keep pointing to them.
 */
class ManageUsers
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function create(User $actor, array $data): User
    {
        $user = DB::transaction(function () use ($actor, $data): User {
            $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
            // Unusable random password: the person sets their own through the reset link.
            $user->forceFill(['password' => Str::password(40), 'email_verified_at' => now()])->save();
            $user->syncRoles([$data['role']]);

            $this->audit->record('user.created', $user, "{$user->name} ({$user->email})", null, [
                'name' => $user->name, 'email' => $user->email, 'role' => $data['role'],
            ], $actor);

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }

    /**
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function update(User $actor, User $user, array $data): void
    {
        $currentRole = $user->getRoleNames()->first();

        if ($actor->is($user) && $currentRole === Role::Admin->value && $data['role'] !== Role::Admin->value) {
            throw ValidationException::withMessages(['form.role' => 'No puedes quitarte a ti mismo el rol de administrador.']);
        }

        DB::transaction(function () use ($actor, $user, $data, $currentRole): void {
            $user->fill(['name' => $data['name'], 'email' => Str::lower($data['email'])]);

            if ($user->isDirty()) {
                $old = array_intersect_key($user->getOriginal(), $user->getDirty());
                $new = $user->getDirty();
                $user->save();
                $this->audit->record('user.updated', $user, $user->name, $old, $new, $actor);
            }

            if ($currentRole !== $data['role']) {
                $user->syncRoles([$data['role']]);
                $this->audit->record('user.role_changed', $user, $user->name,
                    ['role' => $currentRole], ['role' => $data['role']], $actor,
                );
            }
        });
    }

    public function setActive(User $actor, User $user, bool $active): void
    {
        if ($actor->is($user) && ! $active) {
            throw ValidationException::withMessages(['user' => 'No puedes desactivar tu propia cuenta.']);
        }

        if ($user->isActive() === $active) {
            return;
        }

        $user->forceFill(['deactivated_at' => $active ? null : now()])->save();

        $this->audit->record($active ? 'user.reactivated' : 'user.deactivated', $user, $user->name, null, null, $actor);
    }
}
