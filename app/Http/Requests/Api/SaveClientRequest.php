<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Client;
use App\Tenancy\CurrentFirm;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /clients and PATCH|PUT /clients/{id}. Open to every member (the contract
 * names no action key for clients). On update every field is optional and the
 * cross-field rules are checked against the merged result.
 */
final class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The server uppercases the KRA PIN.
        if (is_string($this->input('kra_pin'))) {
            $this->merge(['kra_pin' => Str::upper(trim($this->input('kra_pin')))]);
        }
    }

    /** The client being updated, or null when creating. */
    public function client(): ?Client
    {
        $id = $this->route('client');

        return is_string($id) ? Client::query()->whereKey($id)->first() : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $current = $this->client();

        return [
            'name' => [$required, 'string', 'min:1', 'max:255'],
            'type' => [$required, Rule::in(['individual', 'corporate'])],
            'client_number' => [
                $required, 'string', 'min:1', 'max:50',
                Rule::unique('clients', 'client_number')
                    ->where('firm_id', app(CurrentFirm::class)->id())
                    ->ignore($current?->id, 'id'),
            ],
            'kra_pin' => ['nullable', 'string', 'regex:/^[A-Z]\d{9}[A-Z]$/'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_withholding_agent' => ['sometimes', 'boolean'],
            'is_vat_exempt' => ['sometimes', 'boolean'],
            'vat_exemption_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kra_pin.regex' => 'Use the format A123456789B.',
            'client_number.unique' => 'That client number is already in use.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $current = $this->client();
            $value = fn (string $key, mixed $fallback = null): mixed => $this->has($key)
                ? $this->input($key)
                : ($current === null ? $fallback : $this->stored($current, $key));

            $exempt = (bool) $value('is_vat_exempt', false);

            if ($exempt && ($value('vat_exemption_reference') === null || $value('vat_exemption_reference') === '')) {
                $validator->errors()->add('vat_exemption_reference', 'Enter the exemption reference.');
            }

            if ($exempt && $value('type') === 'corporate' && ($value('kra_pin') === null || $value('kra_pin') === '')) {
                $validator->errors()->add('kra_pin', 'A KRA PIN is required for corporate clients claiming VAT exemption.');
            }
        }];
    }

    /**
     * The columns to write: contract names mapped to storage names.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();
        $out = [];

        foreach (['kra_pin', 'id_number', 'email', 'phone', 'address', 'client_number', 'is_withholding_agent', 'is_vat_exempt', 'vat_exemption_reference'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        if (array_key_exists('name', $data)) {
            $out['full_name'] = $data['name'];
        }

        if (array_key_exists('type', $data)) {
            $out['client_type'] = Client::storedType($data['type']);
        }

        return $out;
    }

    private function stored(Client $client, string $key): mixed
    {
        return $key === 'type' ? $client->apiType() : $client->getAttribute($key);
    }
}