<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Firm;
use App\Models\User;
use App\Policies\Concerns\ResolvesFirmRole;
use App\Auth\Action;

final class FirmPolicy
{
    use ResolvesFirmRole;

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Firm $firm): bool
    {
        return $this->role($user, $firm) !== null;
    }

    public function update(User $user, Firm $firm): bool
    {
        return $this->holds($user, Action::SettingsManage, $firm);
    }

    public function manageAro(User $user, Firm $firm): bool
    {
        return $this->holds($user, Action::SettingsManage, $firm);
    }
}
