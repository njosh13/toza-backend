<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The contract's client (api-contract section 6).
 *
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;

        return [
            'id' => $client->id,
            'name' => $client->full_name,
            'type' => $client->apiType(),
            'client_number' => $client->client_number ?? '',
            'kra_pin' => $client->kra_pin,
            'id_number' => $client->id_number,
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $client->address,
            'is_withholding_agent' => (bool) $client->is_withholding_agent,
            'is_vat_exempt' => (bool) $client->is_vat_exempt,
            'vat_exemption_reference' => $client->vat_exemption_reference,
            'created_at' => $client->created_at?->toIso8601ZuluString(),
            'updated_at' => $client->updated_at?->toIso8601ZuluString(),
        ];
    }
}