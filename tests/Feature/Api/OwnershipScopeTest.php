<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Bill;
use App\Domain\Billing\Models\Payment;
use App\Enums\FirmRole;
use App\Http\Api\Visible;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    seedPublishedAro();

    $this->firm = Firm::factory()->create();
    app(CurrentFirm::class)->set($this->firm);

    $member = function (FirmRole $role, string $name): User {
        $user = User::factory()->create(['name' => $name, 'current_firm_id' => $this->firm->id, 'is_advocate' => true]);
        $this->firm->users()->attach($user, ['role' => $role->value]);

        return $user;
    };

    $this->partner = $member(FirmRole::Partner, 'Peter');
    $this->amina = $member(FirmRole::Associate, 'Amina');
    $this->zawadi = $member(FirmRole::Associate, 'Zawadi');
    $this->accounts = $member(FirmRole::Accounts, 'Brian');
    $this->admin = $member(FirmRole::Admin, 'Grace');

    // Amina's matter, Zawadi's matter, and one nobody is responsible for.
    $this->mAmina = Matter::factory()->create(['firm_id' => $this->firm->id, 'responsible_advocate_id' => $this->amina->id, 'title' => 'Amina matter']);
    $this->mZawadi = Matter::factory()->create(['firm_id' => $this->firm->id, 'responsible_advocate_id' => $this->zawadi->id, 'title' => 'Zawadi matter']);
    $this->mNobody = Matter::factory()->create(['firm_id' => $this->firm->id, 'responsible_advocate_id' => null, 'title' => 'Nobody matter']);

    // bill1: partner wrote it on Amina's matter.   -> Amina sees it (her matter)
    // bill2: Amina wrote it on Zawadi's matter.     -> Amina sees it (she created it)
    // bill3: Zawadi wrote it on Zawadi's matter.    -> Amina must not see it
    $this->bill1 = Bill::factory()->create(['matter_id' => $this->mAmina->id, 'created_by' => $this->partner->id]);
    $this->bill2 = Bill::factory()->create(['matter_id' => $this->mZawadi->id, 'created_by' => $this->amina->id]);
    $this->bill3 = Bill::factory()->create(['matter_id' => $this->mZawadi->id, 'created_by' => $this->zawadi->id]);

    $pay = fn (Bill $bill) => Payment::create(['bill_id' => $bill->id, 'amount_cents' => 1_000_00, 'method' => 'mpesa', 'received_at' => now()->toDateString()]);
    $this->pay1 = $pay($this->bill1);
    $this->pay3 = $pay($this->bill3);

    Route::middleware(['api', 'auth:web'])->prefix('api/v1/_test')->group(function () {
        Route::get('matters', fn (Request $r) => response()->json([
            'data' => Visible::scopedList(Matter::class, $r, $r->user())->orderBy('title')->pluck('title')->all(),
        ]));
        Route::get('matters/{id}', fn (Request $r, string $id) => response()->json([
            'data' => Visible::find(Matter::class, $id, $r->user())->title,
        ]));
        Route::get('bills', fn (Request $r) => response()->json([
            'data' => Visible::scopedList(Bill::class, $r, $r->user())->pluck('id')->all(),
        ]));
        Route::get('bills/{id}', fn (Request $r, string $id) => response()->json([
            'data' => Visible::find(Bill::class, $id, $r->user())->id,
        ]));
    });
});

function titles(iterable $matters): array
{
    return collect($matters)->pluck('title')->sort()->values()->all();
}

it('shows an associate only the matters they are responsible for', function () {
    expect(titles(Matter::query()->visibleTo($this->amina)->get()))->toBe(['Amina matter'])
        ->and(titles(Matter::query()->visibleTo($this->zawadi)->get()))->toBe(['Zawadi matter']);
});

it('shows partners, accounts and admins the whole firm', function (string $who) {
    expect(titles(Matter::query()->visibleTo($this->{$who})->get()))->toBe(['Amina matter', 'Nobody matter', 'Zawadi matter']);
})->with(['partner', 'accounts', 'admin']);

it('shows an associate the bills they created and the bills on their matters', function () {
    $ids = Bill::query()->visibleTo($this->amina)->pluck('id')->all();

    expect($ids)->toContain($this->bill1->id, $this->bill2->id)
        ->and($ids)->not->toContain($this->bill3->id);
});

it('shows an associate only the payments on bills they can see', function () {
    expect(Payment::query()->visibleTo($this->amina)->pluck('id')->all())->toBe([$this->pay1->id])
        ->and(Payment::query()->visibleTo($this->zawadi)->pluck('id')->all())->toBe([$this->pay3->id])
        ->and(Payment::query()->visibleTo($this->accounts)->count())->toBe(2);
});

it('answers 404, never 403, for a record outside the scope', function () {
    $this->actingAs($this->amina);

    $this->getJson("/api/v1/_test/matters/{$this->mAmina->id}")->assertOk()->assertJsonPath('data', 'Amina matter');
    $this->getJson("/api/v1/_test/matters/{$this->mZawadi->id}")->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson("/api/v1/_test/matters/{$this->mNobody->id}")->assertNotFound();
    $this->getJson("/api/v1/_test/bills/{$this->bill3->id}")->assertNotFound();
    $this->getJson("/api/v1/_test/bills/{$this->bill2->id}")->assertOk();
});

it('answers 404 for an id that does not exist or is malformed', function () {
    $this->actingAs($this->partner);

    $this->getJson('/api/v1/_test/matters/'.fake()->uuid())->assertNotFound();
    $this->getJson('/api/v1/_test/matters/not-a-uuid')->assertNotFound();
    $this->getJson('/api/v1/_test/matters/1')->assertNotFound();
});

it('lets a partner read any record in the firm but not another firm\'s', function () {
    $other = Firm::factory()->create();
    $foreign = Matter::factory()->create(['firm_id' => $other->id, 'title' => 'Foreign matter']);

    $this->actingAs($this->partner);

    $this->getJson("/api/v1/_test/matters/{$this->mZawadi->id}")->assertOk();
    $this->getJson("/api/v1/_test/matters/{$foreign->id}")->assertNotFound();
});

it('narrows a partner to one advocate\'s own work with filter[advocate_id]', function () {
    $this->actingAs($this->partner);

    $this->getJson("/api/v1/_test/matters?filter[advocate_id]={$this->amina->public_id}")
        ->assertOk()->assertExactJson(['data' => ['Amina matter']]);
    $this->getJson('/api/v1/_test/matters')
        ->assertExactJson(['data' => ['Amina matter', 'Nobody matter', 'Zawadi matter']]);
});

it('gives a partner filtered to X exactly what X sees when signed in', function () {
    $this->actingAs($this->partner);
    $viaFilter = $this->getJson("/api/v1/_test/bills?filter[advocate_id]={$this->amina->public_id}")->json('data');

    $this->actingAs($this->amina);
    $asAmina = $this->getJson('/api/v1/_test/bills')->json('data');

    expect($viaFilter)->toEqualCanonicalizing($asAmina)->and($asAmina)->toHaveCount(2);
});

it('also accepts the plain advocate_id parameter (dashboard style)', function () {
    $this->actingAs($this->accounts);

    $this->getJson("/api/v1/_test/matters?advocate_id={$this->zawadi->public_id}")
        ->assertExactJson(['data' => ['Zawadi matter']]);
});

it('ignores advocate_id for an associate, who always sees only themselves', function () {
    $this->actingAs($this->amina);

    $this->getJson("/api/v1/_test/matters?filter[advocate_id]={$this->zawadi->public_id}")
        ->assertExactJson(['data' => ['Amina matter']]);
});

it('returns nothing for an unknown advocate or one from another firm', function () {
    $outsider = User::factory()->create();
    Firm::factory()->create()->users()->attach($outsider, ['role' => FirmRole::Partner->value]);

    $this->actingAs($this->partner);

    $this->getJson('/api/v1/_test/matters?filter[advocate_id]=01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertExactJson(['data' => []]);
    $this->getJson("/api/v1/_test/matters?filter[advocate_id]={$outsider->public_id}")->assertExactJson(['data' => []]);
});

it('assigns a matter an associate opens with no responsible advocate to that associate', function () {
    $this->actingAs($this->amina);

    $matter = Matter::create(['client_id' => $this->mAmina->client_id, 'title' => 'New matter', 'court_level' => 'none']);

    expect($matter->responsible_advocate_id)->toBe($this->amina->id)
        ->and(Matter::query()->visibleTo($this->amina)->whereKey($matter->id)->exists())->toBeTrue();
});

it('leaves a partner\'s unassigned matter unassigned, and keeps an explicit advocate', function () {
    $this->actingAs($this->partner);

    $unassigned = Matter::create(['client_id' => $this->mAmina->client_id, 'title' => 'Partner matter', 'court_level' => 'none']);
    $assigned = Matter::create(['client_id' => $this->mAmina->client_id, 'title' => 'Delegated', 'court_level' => 'none', 'responsible_advocate_id' => $this->zawadi->id]);

    expect($unassigned->responsible_advocate_id)->toBeNull()
        ->and($assigned->responsible_advocate_id)->toBe($this->zawadi->id);
});

it('records who authored a bill', function () {
    expect($this->bill2->refresh()->created_by)->toBe($this->amina->id);
});