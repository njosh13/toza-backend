<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FirmRole;

/**
 * Maps a firm membership role to the contract's role keys
 * (admin | partner | associate | accounts).
 *
 * INTERIM (Phase 1): the existing roles are owner/admin/advocate/accounts/
 * readonly. Phase 2 migrates firm_user.role to the contract keys and this
 * class goes away.
 */
final class ApiRole
{
    public static function from(FirmRole|string|null $role): ?string
    {
        if ($role === null) {
            return null;
        }

        $role = $role instanceof FirmRole ? $role : FirmRole::tryFrom($role);

        return match ($role) {
            FirmRole::Owner, FirmRole::Admin => 'admin',
            FirmRole::Advocate => 'partner',
            FirmRole::Accounts, FirmRole::ReadOnly => 'accounts',
            default => null,
        };
    }
}