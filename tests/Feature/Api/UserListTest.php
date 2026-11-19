<?php

declare(strict_types=1);

use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;
use App\Tenancy\CurrentFirm;

function teamOf(Firm $firm, array $members): void
{
    foreach ($members as [$name, $role, $isAdvocate]) {
        $user = User::factory()->create(['name' => $name, 'is_advocate' => $isAdvocate, 'current_firm_id' => $firm->id]);
        $firm->users()->attach($user, ['role' => $role->value]);
    }
}

it('lists the firm members in the contract list envelope', function () {
    $firm = Firm::factory()->create();
    $me = $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Brian Mwangi', FirmRole::Accounts, false]]);

    $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'role', 'is_advocate', 'active', 'created_at', 'updated_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null);
});

it('reports each member\'s role in the current firm', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Brian Mwangi', FirmRole::Accounts, false]]);

    $roles = collect($this->getJson('/api/v1/users')->json('data'))->pluck('role', 'name');

    expect($roles['Amina Hassan'])->toBe('partner')->and($roles['Brian Mwangi'])->toBe('accounts');
});

it('never lists another firm\'s users', function () {
    $mine = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $mine);
    teamOf(Firm::factory()->create(), [['Outsider One', FirmRole::Advocate, true]]);

    $names = collect($this->getJson('/api/v1/users')->json('data'))->pluck('name');

    expect($names)->not->toContain('Outsider One')->and($names)->toHaveCount(1);
});

it('filters advocates for the responsible-advocate dropdown', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Brian Mwangi', FirmRole::Accounts, false], ['Zawadi Njoki', FirmRole::Advocate, true]]);

    $response = $this->getJson('/api/v1/users?filter[is_advocate]=true&per_page=100&sort=name')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Amina Hassan', 'Zawadi Njoki']);
});

it('sorts by name, descending with a minus sign', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Zawadi Njoki', FirmRole::Advocate, true]]);

    $names = collect($this->getJson('/api/v1/users?sort=-name&filter[is_advocate]=true')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Zawadi Njoki', 'Amina Hassan']);
});

it('falls back to the default sort for an unknown sort field', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Zawadi Njoki', FirmRole::Advocate, true]]);

    $names = collect($this->getJson('/api/v1/users?sort=password&filter[is_advocate]=true')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Amina Hassan', 'Zawadi Njoki']);
});

it('searches name and email case-insensitively', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true], ['Brian Mwangi', FirmRole::Accounts, false]]);

    $names = collect($this->getJson('/api/v1/users?q=AMINA')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Amina Hassan']);
});

it('treats % and _ in a search as literal characters', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, [['Amina Hassan', FirmRole::Advocate, true]]);

    $this->getJson('/api/v1/users?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
});

it('caps per_page at 100 and paginates with links', function () {
    $firm = Firm::factory()->create();
    $this->actingAsMember(FirmRole::Owner, $firm);
    teamOf($firm, array_map(fn ($i) => ["Member {$i}", FirmRole::Advocate, true], range(1, 20)));

    $this->getJson('/api/v1/users?per_page=500')->assertJsonPath('meta.per_page', 100);

    $page = $this->getJson('/api/v1/users?per_page=8&page=2&sort=name')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.total', 21)
        ->assertJsonCount(8, 'data');

    expect($page->json('links.next'))->toContain('page=3')->toContain('per_page=8')
        ->and($page->json('links.prev'))->toContain('page=1');
});

it('is closed to guests', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

it('is closed to a user who belongs to no firm', function () {
    $this->actingAs(User::factory()->create());
    app(CurrentFirm::class)->forget();

    $this->getJson('/api/v1/users')->assertForbidden()->assertJsonStructure(['message']);
});