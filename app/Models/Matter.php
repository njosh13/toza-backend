<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Models\Bill;
use App\Domain\Billing\Models\ChargeableItem;
use App\Domain\Billing\Models\FeeAgreement;
use App\Domain\Billing\Models\MatterClassification;
use App\Enums\CourtLevel;
use App\Enums\FeeAgreementType;
use App\Tenancy\BelongsToFirm;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use App\Auth\HasOwnWork;
use App\Enums\FirmRole;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\Forum;
use App\Enums\MatterType;

/**
 * @property string $id
 * @property string $firm_id
 * @property string $client_id
 * @property string $title
 * @property string|null $reference
 * @property CourtLevel $court_level
 * @property string|null $cause_number
 * @property string|null $description
 * @property int|null $value_cents
 * @property string $status
 * @property Carbon|null $opened_on
 * @property-read Client $client
 * @property-read MatterClassification|null $classification
 * @property-read int|null $unbilled_count
 * @property int|null $responsible_advocate_id
 *  * @property MatterType $matter_type
 * @property Forum $forum
 * @property Carbon|null $completed_on
 * @property Carbon|null $schedule5_election_notice_on
 */
#[UseFactory(MatterFactory::class)]
final class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use BelongsToFirm, HasFactory, HasOwnWork, HasUuids, SoftDeletes;

    protected $fillable = [
        'client_id', 'responsible_advocate_id', 'title', 'reference', 'court_level', 'cause_number', 'description',
        'value_cents', 'status', 'opened_on', 'matter_type', 'forum', 'completed_on', 'schedule5_election_notice_on',
    ];

        protected static function booted(): void
    {
        self::saving(function (self $matter): void {
            // matter_type/forum and the legacy court_level describe the same thing.
            // Whichever side was written drives the other.
            $matter->court_level ??= CourtLevel::None;

            $typeWritten = $matter->isDirty('matter_type');
            $levelWritten = $matter->isDirty('court_level');
            $forumWritten = $matter->isDirty('forum');

            if ($typeWritten) {
                $matter->court_level = $matter->matter_type->courtLevel();
            } elseif ($levelWritten || ! $matter->exists) {
                $matter->matter_type = MatterType::fromCourtLevel($matter->court_level);
            }

            if (! $forumWritten && ($levelWritten || ! $matter->exists)) {
                $matter->forum = Forum::fromCourtLevel($matter->court_level);
            }
        });
        // A matter an associate opens with no responsible advocate is theirs,
        // otherwise they could create a matter they are then not allowed to see.
        self::creating(function (self $matter): void {
            $user = auth()->user();

            if ($matter->responsible_advocate_id === null
                && $user instanceof User
                && $matter->firm_id !== null
                && $user->roleIn(Firm::query()->findOrFail($matter->firm_id)) === FirmRole::Associate) {
                $matter->responsible_advocate_id = $user->getKey();
            }
        });
    }

    /** A matter is an associate's own work when they are its responsible advocate. */
    protected function ownWorkConstraint(Builder $query, int $userId): void
    {
        $query->where($this->qualifyColumn('responsible_advocate_id'), $userId);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleAdvocate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_advocate_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasOne<MatterClassification, $this> */
    public function classification(): HasOne
    {
        return $this->hasOne(MatterClassification::class)->where('is_active', true)->latest();
    }

    /** @return HasMany<MatterClassification, $this> */
    public function classifications(): HasMany
    {
        return $this->hasMany(MatterClassification::class);
    }

    /** @return HasMany<FeeAgreement, $this> */
    public function feeAgreements(): HasMany
    {
        return $this->hasMany(FeeAgreement::class);
    }

    /** @return HasMany<ChargeableItem, $this> */
    public function chargeableItems(): HasMany
    {
        return $this->hasMany(ChargeableItem::class)->orderBy('occurred_on')->orderBy('created_at');
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function activeAgreement(FeeAgreementType $type): ?FeeAgreement
    {
        return $this->feeAgreements->first(fn (FeeAgreement $a) => $a->is_active && $a->type === $type);
    }

    /** Para 22: the election must be signified in writing before or with the bill. */
    public function hasCommunicatedElection(): bool
    {
        $election = $this->activeAgreement(FeeAgreementType::Schedule5Election);

        return $election !== null && $election->election_communicated_at !== null;
    }

    public function isExemptFromScale(): bool
    {
        return (bool) $this->classification?->is_exempt;
    }

    protected function casts(): array
    {
        return [
            'court_level' => CourtLevel::class,
            'value_cents' => 'integer',
            'opened_on' => 'date',
            'matter_type' => MatterType::class,
            'forum' => Forum::class,
            'completed_on' => 'date',
            'schedule5_election_notice_on' => 'date',
        ];
    }

    /** The Schedule of the Order, derived from the matter type. */
    public function schedule(): int
    {
        return $this->matter_type->schedule();
    }
}
