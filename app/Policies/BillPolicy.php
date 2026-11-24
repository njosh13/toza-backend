<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\Action;
use App\Domain\Billing\Models\Bill;
use App\Models\User;
use App\Policies\Concerns\ResolvesFirmRole;

final class BillPolicy
{
    use ResolvesFirmRole;

    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    /** Associates see only bills they created or on matters they are responsible for (404 otherwise). */
    public function view(User $user, Bill $bill): bool
    {
        return $this->canSee($user, $bill);
    }

    public function issue(User $user, Bill $bill): bool
    {
        return $this->holds($user, Action::BillIssue) && $bill->isDraft() && $this->canSee($user, $bill);
    }

    public function delete(User $user, Bill $bill): bool
    {
        return $this->holds($user, Action::BillWrite) && $bill->isDraft() && $this->canSee($user, $bill);
    }

    public function deliver(User $user, Bill $bill): bool
    {
        return $this->holds($user, Action::BillWrite) && ! $bill->isDraft() && $this->canSee($user, $bill);
    }

    public function claimInterest(User $user, Bill $bill): bool
    {
        return $this->holds($user, Action::BillWrite) && $bill->delivered_at !== null && $this->canSee($user, $bill);
    }

    public function recordPayment(User $user, Bill $bill): bool
    {
        return $this->holds($user, Action::PaymentRecord) && ! $bill->isDraft() && $this->canSee($user, $bill);
    }

    private function canSee(User $user, Bill $bill): bool
    {
        return $this->role($user) !== null
            && Bill::query()->visibleTo($user)->whereKey($bill->getKey())->exists();
    }
}