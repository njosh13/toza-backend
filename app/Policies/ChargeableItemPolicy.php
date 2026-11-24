<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\ChargeableItem;
use App\Models\User;
use App\Policies\Concerns\ResolvesFirmRole;
use App\Auth\Action;

final class ChargeableItemPolicy
{
    use ResolvesFirmRole;

    public function update(User $user, ChargeableItem $item): bool
    {
        return $this->holds($user, Action::BillWrite);
    }

    public function delete(User $user, ChargeableItem $item): bool
    {
        return $this->holds($user, Action::BillWrite);
    }
}
