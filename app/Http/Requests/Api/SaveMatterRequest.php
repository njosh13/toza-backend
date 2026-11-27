<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\Forum;
use App\Enums\MatterType;
use App\Http\Api\Visible;
use App\Models\Matter;
use App\Models\User;
use App\Support\OrderCalendar;
use App\Tenancy\CurrentFirm;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /matters and PATCH|PUT /matters/{id}. Open to every member; an
 * associate can only reach matters they are responsible for (404 otherwise).
 * `schedule`, `engagement_letter_status` and `schedule5_election_required`
 * are derived and ignored when sent.
 */
final class SaveMatterRequest extends FormRequest
{
    /** Integer id of the responsible advocate, resolved while validating. */
    private ?int $advocateId = null;

    private ?Matter $resolved = null;

    public function authorize(): bool
    {
        return true;
    }

    /** The matter being updated (visible to the user), or null when creating. */
    public function matter(): ?Matter
    {
        $id = $this->route('matter');

        if (! is_string($id)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return $this->resolved ??= Visible::find(Matter::class, $id, $user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $current = $this->matter();
        $firmId = app(CurrentFirm::class)->id();

        return [
            'client_id' => [$required, 'string', Rule::exists('clients', 'id')->where('firm_id', $firmId)->whereNull('deleted_at')],
            'title' => [$required, 'string', 'min:1', 'max:255'],
            'file_reference' => [
                $required, 'string', 'min:1', 'max:100',
                Rule::unique('matters', 'reference')->where('firm_id', $firmId)->ignore($current?->id, 'id'),
            ],
            'matter_type' => [$required, Rule::enum(MatterType::class)],
            'forum' => [$required, Rule::enum(Forum::class)],
            'cause_number' => ['nullable', 'string', 'max:100'],
            'value_amount' => ['nullable', 'integer', 'min:0'],
            'opened_on' => [$required, 'date_format:Y-m-d'],
            'completed_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['open', 'completed', 'closed'])],
            'responsible_advocate_id' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $this->advocateId = Visible::memberIdFor((string) $value);

                if ($this->advocateId === null) {
                    $fail('Choose a member of your firm.');
                }
            }],
            'schedule5_election_notice_on' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if (OrderCalendar::make()->isFuture((string) $value)) {
                    $fail('The notice date cannot be in the future.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file_reference.unique' => 'That file reference is already in use.'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $current = $this->matter();
            $value = fn (string $key): mixed => $this->has($key) ? $this->input($key) : $this->stored($current, $key);

            $touchesCause = $this->has('forum') || $this->has('cause_number');
            if ($touchesCause && $value('forum') === Forum::NonContentious->value && $value('cause_number') !== null && $value('cause_number') !== '') {
                $validator->errors()->add('cause_number', 'A cause number only applies once there is a court or tribunal.');
            }

            $touchesDates = $this->has('opened_on') || $this->has('completed_on');
            $opened = $value('opened_on');
            $completed = $value('completed_on');
            if ($touchesDates && is_string($opened) && is_string($completed) && $completed < $opened) {
                $validator->errors()->add('completed_on', 'Completion cannot be before the matter was opened.');
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

        foreach (['client_id', 'title', 'cause_number', 'opened_on', 'completed_on', 'status', 'schedule5_election_notice_on', 'matter_type', 'forum'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        $map = ['file_reference' => 'reference', 'value_amount' => 'value_cents', 'notes' => 'description'];
        foreach ($map as $api => $column) {
            if (array_key_exists($api, $data)) {
                $out[$column] = $data[$api];
            }
        }

        if (array_key_exists('responsible_advocate_id', $data)) {
            $out['responsible_advocate_id'] = $data['responsible_advocate_id'] === null ? null : $this->advocateId;
        }

        return $out;
    }

    private function stored(?Matter $matter, string $key): mixed
    {
        if ($matter === null) {
            return null;
        }

        return match ($key) {
            'forum' => $matter->forum->value,
            'opened_on' => $matter->opened_on?->format('Y-m-d'),
            'completed_on' => $matter->completed_on?->format('Y-m-d'),
            default => $matter->getAttribute($key),
        };
    }
}