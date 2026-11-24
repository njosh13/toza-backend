<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Auth\Action;
use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;
use App\Tenancy\CurrentFirm;

trait ResolvesFirmRole
{
    /** The user's role in the firm the request acts for (null when not a member). */
    protected function role(User $user, ?Firm $firm = null): ?FirmRole
    {
        $firm ??= app(CurrentFirm::class)->get();

        return $firm === null ? null : $user->roleIn($firm);
    }

    /** Whether the user's role holds the contract action key (see FirmRole::actions()). */
    protected function holds(User $user, Action $action, ?Firm $firm = null): bool
    {
        return $this->role($user, $firm)?->allows($action) ?? false;
    }
}