<?php

declare(strict_types=1);

use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;

function apiUserKeys(): array
{
    return ['id', 'name', 'email', 'role', 'avatar_url', 'is_advocate', 'active', 'last_login_at', 'created_at', 'updated_at', 'unread_notifications_count'];
}

function memberWithPassword(FirmRole $role = FirmRole::Partner, array $attributes = []): User
{
    $firm = Firm::factory()->create();
    $user = User::factory()->create(['password' => 'secret-pass-1', 'current_firm_id' => $firm->id, ...$attributes]);
    $firm->users()->attach($user, ['role' => $role->value]);

    return $user;
}

it('signs in and returns the contract user', function () {
    $user = memberWithPassword(FirmRole::Partner, ['is_advocate' => true]);

    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
        ->assertOk()
        ->assertJsonStructure(['data' => apiUserKeys()])
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', 'partner')
        ->assertJsonPath('data.is_advocate', true)
        ->assertJsonPath('data.active', true);

    $this->assertAuthenticatedAs($user, 'web');
    expect($response->json('data.id'))->toBe($user->fresh()->public_id)
        ->and($response->json('data.id'))->not->toBe((string) $user->id)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('never exposes the sequential integer id', function () {
    $user = memberWithPassword();

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', fn ($id) => is_string($id) && strlen($id) === 26 && ! ctype_digit($id));
});

it('answers 422 on the email field for wrong credentials, not 401', function () {
    $user = memberWithPassword();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'These credentials do not match our records.',
            'errors' => ['email' => ['These credentials do not match our records.']],
        ]);

    $this->assertGuest('web');
});

it('answers 422 with the same message for an unknown email', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('answers 422 on each missing field', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password'])
        ->assertJsonPath('message', fn (string $m) => str_contains($m, '(and 1 more error)'));
});

it('answers 403 for a deactivated user, and only after the password is right', function () {
    $user = memberWithPassword(attributes: ['active' => false]);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    // A wrong password must not reveal that the account is deactivated.
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(422);

    $this->assertGuest('web');
});

it('refuses a password-only sign-in for a two-factor account', function () {
    $user = memberWithPassword();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->assertGuest('web');
});

it('throttles repeated sign-in attempts', function () {
    $user = memberWithPassword();

    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(429);
});

it('answers 401 with a message when not signed in', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns the session user from /auth/me', function () {
    $user = memberWithPassword(FirmRole::Accounts);

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonStructure(['data' => apiUserKeys()])
        ->assertJsonPath('data.role', 'accounts')
        ->assertJsonPath('data.unread_notifications_count', 0);
});

it('lets a user change only their name', function () {
    $user = memberWithPassword();

    $this->actingAs($user)
        ->patchJson('/api/v1/auth/me', ['name' => 'Grace Wambui', 'email' => 'hijack@example.com', 'role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Grace Wambui')
        ->assertJsonPath('data.email', $user->email);

    expect($user->fresh()->email)->toBe($user->email);
});

it('requires a name of at least two characters', function () {
    $this->actingAs(memberWithPassword())
        ->patchJson('/api/v1/auth/me', ['name' => 'G'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('signs out with 204', function () {
    $user = memberWithPassword();

    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('drops the session of a user deactivated mid-session', function () {
    $user = memberWithPassword();
    $user->forceFill(['active' => false])->save();

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('issues the XSRF cookie from /sanctum/csrf-cookie', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('allows the configured frontend origin to send credentials (CORS)', function () {
    $this->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('does not allow an unknown origin (CORS)', function () {
    $response = $this->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});

it('signs in whatever the case of the email address', function () {
    $user = memberWithPassword();

    $this->postJson('/api/v1/auth/login', ['email' => '  '.strtoupper($user->email).' ', 'password' => 'secret-pass-1'])
        ->assertOk()->assertJsonPath('data.email', $user->email);
});