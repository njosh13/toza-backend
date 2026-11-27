<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToFirm;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $firm_id
 * @property string $full_name
 * @property string|null $client_number
 * @property string $client_type
 * @property string|null $kra_pin
 * @property string|null $id_number
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property bool $is_withholding_agent
 * @property bool $is_vat_exempt
 * @property string|null $vat_exemption_reference
 * @property-read int|null $matters_count
 */
#[UseFactory(ClientFactory::class)]
final class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToFirm, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'full_name', 'client_number', 'client_type', 'kra_pin', 'id_number', 'email', 'phone',
        'address', 'is_withholding_agent', 'is_vat_exempt', 'vat_exemption_reference',
    ];

    /** @return HasMany<Matter, $this> */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    protected function casts(): array
    {
        return ['is_withholding_agent' => 'boolean', 'is_vat_exempt' => 'boolean'];
    }

    /** The contract's type: `individual` or `corporate` (a company or a government body). */
    public function apiType(): string
    {
        return $this->client_type === 'individual' ? 'individual' : 'corporate';
    }

    /** The stored type for a contract type. */
    public static function storedType(string $apiType): string
    {
        return $apiType === 'corporate' ? 'company' : 'individual';
    }
}
