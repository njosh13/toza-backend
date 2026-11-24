<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Auth\Action;
use App\Enums\FirmRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** POST /users (team.manage). */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows(Action::TeamManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                // Case-insensitive: Postgres compares text exactly.
                if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                    $fail('The email has already been taken.');
                }
            }],
            'role' => ['required', 'string', Rule::in(array_column(FirmRole::cases(), 'value'))],
            'is_advocate' => ['sometimes', 'boolean'],
        ];
    }
}