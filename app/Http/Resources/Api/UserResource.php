<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use App\Support\ApiRole;
use App\Tenancy\CurrentFirm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The contract's user (api-contract section 2). `role` is the user's role in
 * the current firm.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $this->role($user),
            'avatar_url' => $user->avatar_url,
            'is_advocate' => (bool) $user->is_advocate,
            'active' => (bool) ($user->active ?? true),
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'updated_at' => $user->updated_at?->toIso8601ZuluString(),
            // Filled in by Phase 7 (notifications).
            'unread_notifications_count' => 0,
        ];
    }

    private function role(User $user): ?string
    {
        $joined = $user->getAttribute('membership_role');

        if (is_string($joined)) {
            return ApiRole::from($joined);
        }

        $firm = app(CurrentFirm::class)->get();

        return $firm === null ? null : ApiRole::from($user->roleIn($firm));
    }
}