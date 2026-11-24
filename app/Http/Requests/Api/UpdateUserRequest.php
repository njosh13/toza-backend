<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Auth\Action;
use App\Enums\FirmRole;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PATCH|PUT /users/{user} (team.manage). `{user}` is the opaque public id,
 * and the person must belong to the current firm (404 otherwise).
 */
final class UpdateUserRequest extends FormRequest
{
    private ?User $target = null;

    public function authorize(): bool
    {
        return Gate::allows(Action::TeamManage->value);
    }

    /** The member being edited, resolved by public id inside the current firm. */
    public function target(): User
    {
        return $this->target ??= $this->resolveTarget();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $targetId = $this->target()->getKey();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($targetId): void {
                $taken = User::query()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])
                    ->where('id', '!=', $targetId)
                    ->exists();

                if ($taken) {
                    $fail('The email has already been taken.');
                }
            }],
            'role' => ['sometimes', 'required', 'string', Rule::in(array_column(FirmRole::cases(), 'value'))],
            'is_advocate' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function resolveTarget(): User
    {
        $firm = app(CurrentFirm::class)->get();
        $publicId = (string) $this->route('user');

        $user = $firm === null ? null : User::query()
            ->join('firm_user', 'firm_user.user_id', '=', 'users.id')
            ->where('firm_user.firm_id', $firm->id)
            ->where('users.public_id', $publicId)
            ->select('users.*')
            ->first();

        return $user ?? abort(404, 'Not found.');
    }
}