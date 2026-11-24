<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The ownership scope (api-contract section 2), written once.
 *
 * A model says what "own work of user X" means by implementing
 * `ownWorkConstraint()`. Everything else follows from that one definition:
 *  - `ownWork($user)`   the rows that are that user's own work (also what
 *                       `filter[advocate_id]` and `?advocate_id=` narrow to);
 *  - `visibleTo($user)` everything for roles that hold `data.firm`, otherwise
 *                       only their own work. Associates are the case today.
 *
 * Records outside the scope are treated as not existing (404), never 403.
 *
 * @mixin Model
 */
trait HasOwnWork
{
    /** Constrain $query to the rows that are the own work of the user with this id. */
    abstract protected function ownWorkConstraint(Builder $query, int $userId): void;

    /** @param  Builder<static>  $query */
    public function scopeOwnWork(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $query->where(fn (Builder $q) => $this->ownWorkConstraint($q, (int) $userId));
    }

    /** @param  Builder<static>  $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (Gate::forUser($user)->allows(Action::DataFirm->value)) {
            return;
        }

        $this->scopeOwnWork($query, $user);
    }
}