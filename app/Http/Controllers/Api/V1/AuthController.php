<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\UpdateMeRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** api-contract section 2: cookie-session auth for the SPA. */
final class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        // Wrong credentials are a 422 on `email` (NOT 401): the form shows it.
        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['email' => [trans('auth.failed')]]);
        }

        if ($user->getAttribute('active') === false) {
            abort(403, 'Your account has been deactivated.');
        }

        // The API sign-in has no second step yet. Refusing is safer than letting
        // a two-factor account in with the password alone.
        if ($user->two_factor_confirmed_at !== null) {
            throw ValidationException::withMessages([
                'email' => ['Two-factor authentication is enabled on this account and is not supported here yet.'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        User::query()->whereKey($user->getKey())->update(['last_login_at' => now()]);

        return ApiResponse::item(new UserResource($user->refresh()));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::item(new UserResource($request->user()));
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['name' => $request->validated('name')]);

        return ApiResponse::item(new UserResource($user->refresh()));
    }
}