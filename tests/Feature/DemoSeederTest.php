<?php

declare(strict_types=1);

use App\Enums\FirmRole;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    seedAro();
    $this->seed(DemoSeeder::class);

    $this->as = function (string $email): User {
        $user = User::query()->where('email', $email)->firstOrFail();
        $firm = $user->resolveCurrentFirm();
        app(CurrentFirm::class)->set($firm);
        $this->actingAs($user);

        return $user;
    };
});

it('seeds one demo user per contract role, plus a second associate', function () {
    $roles = ['admin@example.com' => FirmRole::Admin, 'test@example.com' => FirmRole::Partner, 'partner@example.com' => FirmRole::Partner,
        'associate@example.com' => FirmRole::Associate, 'associate2@example.com' => FirmRole::Associate, 'accounts@example.com' => FirmRole::Accounts];

    foreach ($roles as $email => $role) {
        $user = ($this->as)($email);
        expect($user->roleIn($user->resolveCurrentFirm()))->toBe($role, $email);
    }
});

it('gives every demo user the password "password"', function () {
    foreach (['admin@example.com', 'test@example.com', 'associate@example.com', 'mutua@example.com'] as $email) {
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/logout');
    }
});

it('hides other associates\' matters from an associate but not from a partner', function () {
    $amina = ($this->as)('associate@example.com');
    expect(Matter::query()->visibleTo($amina)->pluck('title')->all())->toHaveCount(1)
        ->and(Matter::query()->visibleTo($amina)->first()->responsible_advocate_id)->toBe($amina->id);

    $zawadi = ($this->as)('associate2@example.com');
    expect(Matter::query()->visibleTo($zawadi)->count())->toBe(1);

    $partner = ($this->as)('test@example.com');
    expect(Matter::query()->visibleTo($partner)->count())->toBe(3);
});

it('keeps the second firm completely apart from the first', function () {
    $lucy = ($this->as)('mutua@example.com');

    expect(Client::query()->pluck('full_name')->all())->toBe(['Mwangi Traders Ltd'])
        ->and(Matter::query()->count())->toBe(0);

    $names = collect($this->getJson('/api/v1/users')->json('data'))->pluck('name')->all();
    expect($names)->toBe([$lucy->name]);

    ($this->as)('test@example.com');
    expect(Client::query()->pluck('full_name')->all())->not->toContain('Mwangi Traders Ltd')
        ->and(collect($this->getJson('/api/v1/users?per_page=100')->json('data')))->toHaveCount(6);
});