<?php

declare(strict_types=1);

namespace App\Domain\Team;

use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Team management for one firm (api-contract section 15). There is no delete:
 * a user is deactivated so history keeps its authors.
 */
final class TeamMembers
{
    /**
     * @param  array{name: string, email: string, role: string, is_advocate?: bool}  $data
     */
    public function create(Firm $firm, array $data): User
    {
        $user = DB::transaction(function () use ($firm, $data): User {
            $user = new User(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email']))]);
            // No one knows this password: the person sets their own through the reset link.
            $user->password = Str::password(40);
            $user->forceFill([
                'email_verified_at' => now(),
                'is_advocate' => (bool) ($data['is_advocate'] ?? false),
                'active' => true,
                'current_firm_id' => $firm->id,
            ])->save();

            $firm->users()->attach($user->id, ['role' => $data['role']]);

            return $user;
        });

        $this->invite($user);

        return $user->setAttribute('membership_role', $data['role']);
    }

    /**
     * @param  array{name?: string, email?: string, role?: string, is_advocate?: bool, active?: bool}  $changes
     */
    public function update(Firm $firm, User $actor, User $target, array $changes): User
    {
        $currentRole = $target->roleIn($firm) ?? throw new \LogicException('Target is not a member of the firm.');
        $newRole = FirmRole::from($changes['role'] ?? $currentRole->value);
        $newActive = (bool) ($changes['active'] ?? $target->active);

        $this->guardSelf($actor, $target, $currentRole, $newRole, $newActive);
        $this->guardLastAdmin($firm, $target, $currentRole, $newRole, $newActive);

        DB::transaction(function () use ($firm, $target, $changes, $newRole, $newActive): void {
            $target->forceFill(array_filter([
                'name' => $changes['name'] ?? null,
                'email' => isset($changes['email']) ? mb_strtolower(trim($changes['email'])) : null,
                'is_advocate' => $changes['is_advocate'] ?? null,
            ], fn ($v) => $v !== null))->forceFill(['active' => $newActive])->save();

            $firm->users()->updateExistingPivot($target->id, ['role' => $newRole->value]);
        });

        return $target->refresh()->setAttribute('membership_role', $newRole->value);
    }

    /** You cannot change your own role or deactivate yourself. */
    private function guardSelf(User $actor, User $target, FirmRole $currentRole, FirmRole $newRole, bool $newActive): void
    {
        if (! $actor->is($target)) {
            return;
        }

        $errors = [];

        if ($newRole !== $currentRole) {
            $errors['role'] = ['You cannot change your own role.'];
        }

        if (! $newActive) {
            $errors['active'] = ['You cannot deactivate yourself.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The firm must keep at least one active administrator. */
    private function guardLastAdmin(Firm $firm, User $target, FirmRole $currentRole, FirmRole $newRole, bool $newActive): void
    {
        $losesAdmin = $currentRole === FirmRole::Admin && $target->active && ($newRole !== FirmRole::Admin || ! $newActive);

        if (! $losesAdmin) {
            return;
        }

        $others = $firm->users()
            ->wherePivot('role', FirmRole::Admin->value)
            ->where('users.active', true)
            ->where('users.id', '!=', $target->id)
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                $newRole !== FirmRole::Admin ? 'role' : 'active' => ['The firm must keep at least one active administrator.'],
            ]);
        }
    }

    /** Send the new member a link to choose their password. A mail failure never undoes the account. */
    private function invite(User $user): void
    {
        try {
            Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}