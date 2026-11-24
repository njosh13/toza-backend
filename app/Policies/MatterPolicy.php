<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\Action;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ResolvesFirmRole;

final class MatterPolicy
{
    use ResolvesFirmRole;

    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    /** Associates see only matters they are responsible for (404 otherwise). */
    public function view(User $user, Matter $matter): bool
    {
        return $this->role($user) !== null
            && Matter::query()->visibleTo($user)->whereKey($matter->getKey())->exists();
    }

    /** The contract names no action key for matters: every member may open and edit them. */
    public function create(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function update(User $user, Matter $matter): bool
    {
        return $this->view($user, $matter);
    }

    /** Chargeable work, classification, fee agreements and bills on the matter. */
    public function bill(User $user, Matter $matter): bool
    {
        return $this->holds($user, Action::BillWrite) && $this->view($user, $matter);
    }
}