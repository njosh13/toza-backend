<?php

declare(strict_types=1);

use App\Auth\Action;
use App\Enums\FirmRole;
use App\Models\Firm;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Support\Facades\Gate;

/**
 * The contract's role matrix (api-contract section 2), typed out independently
 * of the code under test. P partner, A associate, C accounts, M admin.
 *
 * @return array<string, string>
 */
function contractMatrix(): array
{
    return [
        'estimate.write' => 'PA',
        'bill.write' => 'PA',
        'bill.review' => 'P',
        'bill.issue' => 'P',
        'payment.record' => 'PAC',
        'payment.void' => 'P',
        'bill.write_off' => 'P',
        'floor.override' => 'P',
        'engagement.write' => 'PA',
        'taxation.write' => 'PA',
        'reports.firm' => 'PACM',
        'data.firm' => 'PCM',
        'settings.manage' => 'M',
        'team.manage' => 'M',
        'audit.view' => 'M',
    ];
}

function roleLetter(FirmRole $role): string
{
    return match ($role) {
        FirmRole::Partner => 'P',
        FirmRole::Associate => 'A',
        FirmRole::Accounts => 'C',
        FirmRole::Admin => 'M',
    };
}

function userWithRole(FirmRole $role, ?Firm $firm = null): User
{
    $firm ??= Firm::factory()->create();
    $user = User::factory()->create(['current_firm_id' => $firm->id]);
    $firm->users()->attach($user, ['role' => $role->value]);
    app(CurrentFirm::class)->set($firm);

    return $user;
}

it('defines exactly the contract roles and action keys', function () {
    expect(array_column(FirmRole::cases(), 'value'))->toBe(['admin', 'partner', 'associate', 'accounts'])
        ->and(array_column(Action::cases(), 'value'))->toEqualCanonicalizing(array_keys(contractMatrix()));
});

it('grants each role exactly the keys in the contract matrix', function (FirmRole $role) {
    $user = userWithRole($role);

    foreach (contractMatrix() as $key => $letters) {
        $expected = str_contains($letters, roleLetter($role));

        expect(Gate::forUser($user)->allows($key))->toBe($expected, "{$role->value} / {$key}")
            ->and($role->allows(Action::from($key)))->toBe($expected, "{$role->value} / {$key} (enum)");
    }
})->with(fn () => FirmRole::cases());

it('grants nothing to a user with no role in the current firm', function () {
    $firm = Firm::factory()->create();
    $outsider = User::factory()->create();
    app(CurrentFirm::class)->set($firm);

    foreach (array_keys(contractMatrix()) as $key) {
        expect(Gate::forUser($outsider)->allows($key))->toBeFalse($key);
    }
});

it('grants nothing when there is no current firm', function () {
    $user = User::factory()->create();
    app(CurrentFirm::class)->set(null);

    expect(Gate::forUser($user)->allows('bill.write'))->toBeFalse();
});

it('uses the role in the CURRENT firm for a user who belongs to two firms', function () {
    $a = Firm::factory()->create();
    $b = Firm::factory()->create();
    $user = User::factory()->create(['current_firm_id' => $a->id]);
    $a->users()->attach($user, ['role' => FirmRole::Partner->value]);
    $b->users()->attach($user, ['role' => FirmRole::Accounts->value]);

    app(CurrentFirm::class)->set($a);
    expect(Gate::forUser($user)->allows('bill.issue'))->toBeTrue();

    app(CurrentFirm::class)->set($b);
    expect(Gate::forUser($user)->allows('bill.issue'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payment.record'))->toBeTrue();
});

it('lets only administrators update the firm profile', function () {
    $firm = Firm::factory()->create();
    $admin = userWithRole(FirmRole::Admin, $firm);
    $partner = userWithRole(FirmRole::Partner, $firm);
    $associate = userWithRole(FirmRole::Associate, $firm);

    expect(Gate::forUser($admin)->allows('update', $firm))->toBeTrue()
        ->and(Gate::forUser($partner)->allows('update', $firm))->toBeFalse()
        ->and(Gate::forUser($associate)->allows('update', $firm))->toBeFalse();
});

it('says which roles see only their own work', function () {
    expect(FirmRole::Associate->seesOnlyOwnWork())->toBeTrue()
        ->and(FirmRole::Partner->seesOnlyOwnWork())->toBeFalse()
        ->and(FirmRole::Accounts->seesOnlyOwnWork())->toBeFalse()
        ->and(FirmRole::Admin->seesOnlyOwnWork())->toBeFalse();
});