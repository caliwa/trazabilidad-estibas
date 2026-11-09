<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User administration. The administrator sets the password when creating
 * the account and can set a new one later; the person signs in with it.
 * Users are deactivated, never deleted, so the pallets, counts and
 * documents they touched keep pointing to them.
 */
class ManageUsers
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, role: string, password: string}  $data
     */
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
            $user->forceFill(['password' => $data['password'], 'email_verified_at' => now()])->save();
            $user->syncRoles([$data['role']]);

            $this->audit->record('user.created', $user, "{$user->name} ({$user->email})", null, [
                'name' => $user->name, 'email' => $user->email, 'role' => $data['role'],
            ], $actor);

            return $user;
        });
    }

    /**
     * A new password set by the administrator. The person's other sessions
     * and "remember me" are closed, so only the new password gets in. The
     * audit says who changed it, never the password.
     */
    public function setPassword(User $actor, User $user, string $password): void
    {
        DB::transaction(function () use ($actor, $user, $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            if (config('session.driver') === 'database') {
                DB::table(config()->string('session.table', 'sessions'))->where('user_id', $user->id)
                    // Changing your own: this session stays open.
                    ->when($actor->is($user), fn ($q) => $q->where('id', '!=', session()->getId()))
                    ->delete();
            }

            $this->audit->record('user.password_set', $user, $user->name, null, null, $actor);
        });
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
