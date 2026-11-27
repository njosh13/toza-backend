<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Models\Bill;
use Database\Factories\FirmFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $plan
 * @property string $isolation
 * @property string|null $kra_pin
 * @property string|null $lsk_firm_number
 * @property string|null $address
 * @property string|null $email
 * @property string|null $phone
 * @property bool $vat_registered
 * @property string $rounding_policy
 * @property string $default_cost_basis
 * @property string $bill_number_prefix
 * @property int $bill_sequence
 * @property list<string>|null $address_lines
 * @property string|null $website
 * @property string|null $logo_url
 * @property string|null $logo_path
 * @property array<string, mixed>|null $defaults
 */
#[UseFactory(FirmFactory::class)]
final class Firm extends Model
{
    /** @use HasFactory<FirmFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'plan', 'kra_pin', 'lsk_firm_number', 'address', 'email', 'phone',
        'vat_registered', 'rounding_policy', 'default_cost_basis', 'bill_number_prefix',
        'address_lines', 'website', 'logo_url', 'logo_path', 'defaults', ...self::WORDING,
    ];

    /** Wording blocks, each a list of paragraphs or lines (api-contract section 15). */
public const WORDING = [
    'estimate_terms', 'bill_terms', 'engagement_terms', 'election_notice',
    'certificate_62a', 'certificate_68a', 'bank_details', 'mpesa_details',
];

/** Defaults of `defaults` (api-contract section 15). */
public const DEFAULT_SETTINGS = [
    'require_second_reviewer' => false,
    'floor_protection_mode' => 'warn',
    'reminder_lead_days' => [7, 3, 1],
];

protected static function booted(): void
{
    // The legacy single-text address and the contract's list of lines stay in step.
    self::saving(function (self $firm): void {
        if ($firm->isDirty('address_lines') && ! $firm->isDirty('address')) {
            $firm->address = $firm->address_lines === null ? null : implode("\n", $firm->address_lines);
        } elseif ($firm->isDirty('address') && ! $firm->isDirty('address_lines')) {
            $firm->address_lines = $firm->address === null
                ? null
                : array_values(array_filter(array_map('trim', preg_split('/\R/', $firm->address) ?: [])));
        }
    });
}

    /**
     * The firm's defaults with the stored values merged over the built-in ones.
     *
     * @return array{require_second_reviewer: bool, floor_protection_mode: string, reminder_lead_days: list<int>}
     */

    public function settings(): array
    {
        /** @var array{require_second_reviewer: bool, floor_protection_mode: string, reminder_lead_days: list<int>} */
        return [...self::DEFAULT_SETTINGS, ...($this->defaults ?? [])];
    }

    /** @return BelongsToMany<User, $this, FirmUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(FirmUser::class)
            ->withPivot(['role', 'hourly_rate_cents'])
            ->withTimestamps();
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Matter, $this> */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function roundsToShilling(): bool
    {
        return $this->rounding_policy === 'shilling_half_up';
    }

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'bill_sequence' => 'integer',
            'address_lines' => 'array',
            'defaults' => 'array',
            ...array_fill_keys(self::WORDING, 'array'),
        ];
    }
}
