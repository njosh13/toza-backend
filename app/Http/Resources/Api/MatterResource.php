<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The contract's matter (api-contract section 6). `client` is embedded only
 * when the relation was loaded (`?include=client`).
 *
 * @mixin Matter
 */
final class MatterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Matter $matter */
        $matter = $this->resource;

        $data = [
            'id' => $matter->id,
            'client_id' => $matter->client_id,
            'title' => $matter->title,
            'file_reference' => $matter->reference ?? '',
            'matter_type' => $matter->matter_type->value,
            'schedule' => $matter->schedule(),
            'forum' => $matter->forum->value,
            'cause_number' => $matter->cause_number,
            'value_amount' => $matter->value_cents,
            'opened_on' => ($matter->opened_on ?? $matter->created_at)?->format('Y-m-d'),
            'completed_on' => $matter->completed_on?->format('Y-m-d'),
            'status' => $matter->status,
            'responsible_advocate_id' => $this->advocatePublicId($matter),
            'schedule5_election_notice_on' => $matter->schedule5_election_notice_on?->format('Y-m-d'),
            // Derived. Both stay at their neutral value until estimates, letters and
            // bills carry the contract lifecycle (phases 5 and 6).
            'engagement_letter_status' => 'none',
            'schedule5_election_required' => false,
            'notes' => $matter->description,
            'created_at' => $matter->created_at?->toIso8601ZuluString(),
            'updated_at' => $matter->updated_at?->toIso8601ZuluString(),
        ];

        if ($matter->relationLoaded('client') && $matter->client !== null) {
            $data['client'] = (new ClientResource($matter->client))->resolve($request);
        }

        return $data;
    }

    private function advocatePublicId(Matter $matter): ?string
    {
        if ($matter->responsible_advocate_id === null) {
            return null;
        }

        return $matter->relationLoaded('responsibleAdvocate')
            ? $matter->responsibleAdvocate?->public_id
            : $matter->responsibleAdvocate()->value('public_id');
    }
}