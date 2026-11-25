<?php

declare(strict_types=1);

use App\Domain\Team\TeamMembers;
use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->firm = Firm::factory()->create();
    app(CurrentFirm::class)->set($this->firm);

    $member = function (FirmRole $role, string $name, array $attributes = []): User {
        $user = User::factory()->create(['name' => $name, 'current_firm_id' => $this->firm->id, ...$attributes]);
        $this->firm->users()->attach($user, ['role' => $role->value]);

        return $user;
    };

    $this->admin = $member(FirmRole::Admin, 'Grace Njeri');
    $this->partner = $member(FirmRole::Partner, 'Peter Otieno');
    $this->associate = $member(FirmRole::Associate, 'Amina Hassan');
    $this->accounts = $member(FirmRole::Accounts, 'Brian Mwangi');
    $this->member = $member;
});

function newUserPayload(array $overrides = []): array
{
    return ['name' => 'Zawadi Njoki', 'email' => 'zawadi@example.com', 'role' => 'associate', 'is_advocate' => true, ...$overrides];
}

// --- POST /users -------------------------------------------------------------

it('lets an administrator add a team member and sends the invitation', function () {
    Notification::fake();

    $response = $this->actingAs($this->admin)->postJson('/api/v1/users', newUserPayload(['email' => 'Zawadi@Example.COM']))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role', 'avatar_url', 'is_advocate', 'active', 'last_login_at', 'created_at', 'updated_at', 'unread_notifications_count']])
        ->assertJsonPath('data.name', 'Zawadi Njoki')
        ->assertJsonPath('data.email', 'zawadi@example.com')
        ->assertJsonPath('data.role', 'associate')
        ->assertJsonPath('data.is_advocate', true)
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.last_login_at', null);

    $created = User::query()->where('email', 'zawadi@example.com')->firstOrFail();

    expect($response->json('data.id'))->toBe($created->public_id)
        ->and($this->firm->users()->whereKey($created->id)->exists())->toBeTrue()
        ->and($created->roleIn($this->firm))->toBe(FirmRole::Associate);

    Notification::assertSentTo($created, ResetPassword::class);
});

it('defaults is_advocate to false', function () {
    $payload = newUserPayload(['role' => 'accounts']);
    unset($payload['is_advocate']);

    $this->actingAs($this->admin)->postJson('/api/v1/users', $payload)
        ->assertCreated()->assertJsonPath('data.is_advocate', false);
});

it('creates a member who cannot sign in until they choose a password', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/users', newUserPayload())->assertCreated();

    $this->postJson('/api/v1/auth/login', ['email' => 'zawadi@example.com', 'password' => 'password'])->assertUnprocessable();
});

it('forbids everyone but an administrator from adding members', function (string $who) {
    $this->actingAs($this->{$who})->postJson('/api/v1/users', newUserPayload())->assertForbidden()->assertJsonStructure(['message']);

    expect(User::query()->where('email', 'zawadi@example.com')->exists())->toBeFalse();
})->with(['partner', 'associate', 'accounts']);

it('answers 401 to a guest', function () {
    $this->postJson('/api/v1/users', newUserPayload())->assertUnauthorized();
    $this->patchJson('/api/v1/users/'.$this->partner->public_id, ['name' => 'X Y'])->assertUnauthorized();
});

it('rejects a duplicate email whatever its case, including one from another firm', function () {
    $other = User::factory()->create(['email' => 'taken@example.com']);
    Firm::factory()->create()->users()->attach($other, ['role' => FirmRole::Partner->value]);

    $this->actingAs($this->admin);

    $this->postJson('/api/v1/users', newUserPayload(['email' => strtoupper($this->partner->email)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson('/api/v1/users', newUserPayload(['email' => 'Taken@Example.com']))
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('validates name, email and role', function () {
    $this->actingAs($this->admin);

    $this->postJson('/api/v1/users', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role']);
    $this->postJson('/api/v1/users', newUserPayload(['name' => 'Z']))->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/users', newUserPayload(['email' => 'not-an-email']))->assertJsonValidationErrors(['email']);
    $this->postJson('/api/v1/users', newUserPayload(['role' => 'owner']))->assertJsonValidationErrors(['role']);
    $this->postJson('/api/v1/users', newUserPayload(['role' => 'readonly']))->assertJsonValidationErrors(['role']);
});

it('puts the new member in the administrator\'s firm and lists them', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/users', newUserPayload())->assertCreated();

    $names = collect($this->getJson('/api/v1/users?per_page=100')->json('data'))->pluck('name');

    expect($names)->toContain('Zawadi Njoki')->toHaveCount(5);
});

// --- PATCH /users/{id} -------------------------------------------------------

it('updates a member\'s name, email, role and advocate flag', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->associate->public_id, [
        'name' => 'Amina H. Hassan', 'email' => 'AMINA.new@example.com', 'role' => 'partner', 'is_advocate' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $this->associate->public_id)
        ->assertJsonPath('data.name', 'Amina H. Hassan')
        ->assertJsonPath('data.email', 'amina.new@example.com')
        ->assertJsonPath('data.role', 'partner')
        ->assertJsonPath('data.is_advocate', false);

    expect($this->associate->refresh()->roleIn($this->firm))->toBe(FirmRole::Partner);
});

it('accepts PUT as well as PATCH, and a partial body', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/users/'.$this->accounts->public_id, ['name' => 'Brian K. Mwangi'])
        ->assertOk()->assertJsonPath('data.name', 'Brian K. Mwangi')->assertJsonPath('data.role', 'accounts');
});

it('forbids everyone but an administrator from editing members', function (string $who) {
    $this->actingAs($this->{$who})->patchJson('/api/v1/users/'.$this->accounts->public_id, ['name' => 'Changed Name'])->assertForbidden();

    expect($this->accounts->refresh()->name)->toBe('Brian Mwangi');
})->with(['partner', 'associate', 'accounts']);

it('answers 404 for an unknown user, a user of another firm, or an integer id', function () {
    $outsider = User::factory()->create();
    Firm::factory()->create()->users()->attach($outsider, ['role' => FirmRole::Partner->value]);

    $this->actingAs($this->admin);

    $this->patchJson('/api/v1/users/01HZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'Nobody'])->assertNotFound();
    $this->patchJson('/api/v1/users/'.$outsider->public_id, ['name' => 'Hijacked'])->assertNotFound();
    $this->patchJson('/api/v1/users/'.$this->partner->id, ['name' => 'By Integer'])->assertNotFound();

    expect($outsider->refresh()->name)->not->toBe('Hijacked');
});

it('keeps email unique across other users but lets a member keep their own', function () {
    $this->actingAs($this->admin);

    $this->patchJson('/api/v1/users/'.$this->associate->public_id, ['email' => strtoupper($this->partner->email)])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->patchJson('/api/v1/users/'.$this->associate->public_id, ['email' => strtoupper($this->associate->email), 'name' => 'Amina Again'])
        ->assertOk();
});

it('refuses to let an administrator change their own role', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->admin->public_id, ['role' => 'partner'])
        ->assertUnprocessable()->assertJsonValidationErrors(['role']);

    expect($this->admin->roleIn($this->firm))->toBe(FirmRole::Admin);
});

it('refuses to let an administrator deactivate themselves', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->admin->public_id, ['active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['active']);

    expect($this->admin->refresh()->active)->toBeTrue();
});

it('lets an administrator edit their own name, or send their own role unchanged', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->admin->public_id, ['name' => 'Grace W. Njeri', 'role' => 'admin', 'active' => true])
        ->assertOk()->assertJsonPath('data.name', 'Grace W. Njeri');
});

it('deactivates a member, who is then refused sign-in but stays in the list', function () {
    $this->associate->forceFill(['password' => 'secret-pass-1'])->save();

    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->associate->public_id, ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);

    $this->postJson('/api/v1/auth/login', ['email' => $this->associate->email, 'password' => 'secret-pass-1'])->assertForbidden();

    $listed = collect($this->getJson('/api/v1/users?per_page=100')->json('data'))->firstWhere('id', $this->associate->public_id);
    expect($listed['active'])->toBeFalse();
});

it('signs out a member who is deactivated while signed in', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->partner->public_id, ['active' => false])->assertOk();

    $this->actingAs($this->partner->refresh())->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('reactivates a member', function () {
    $this->partner->forceFill(['active' => false])->save();

    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->partner->public_id, ['active' => true])
        ->assertOk()->assertJsonPath('data.active', true);
});

it('rejects an unknown role on update', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/users/'.$this->partner->public_id, ['role' => 'owner'])
        ->assertUnprocessable()->assertJsonValidationErrors(['role']);
});

// --- the last-administrator rule (reached through the service) ---------------

it('keeps at least one active administrator', function () {
    $team = app(TeamMembers::class);
    // An actor who is not the target, so the self rule does not fire first.
    $actor = User::factory()->create();

    expect(fn () => $team->update($this->firm, $actor, $this->admin, ['role' => 'partner']))
        ->toThrow(ValidationException::class, 'at least one active administrator');
    expect(fn () => $team->update($this->firm, $actor, $this->admin, ['active' => false]))
        ->toThrow(ValidationException::class, 'at least one active administrator');

    // A second administrator who is inactive does not count.
    ($this->member)(FirmRole::Admin, 'Dormant Admin', ['active' => false]);
    expect(fn () => $team->update($this->firm, $actor, $this->admin, ['role' => 'partner']))
        ->toThrow(ValidationException::class);

    // With another ACTIVE administrator the first can step down.
    ($this->member)(FirmRole::Admin, 'Second Admin');
    $team->update($this->firm, $actor, $this->admin, ['role' => 'partner']);

    expect($this->admin->refresh()->roleIn($this->firm))->toBe(FirmRole::Partner);
});