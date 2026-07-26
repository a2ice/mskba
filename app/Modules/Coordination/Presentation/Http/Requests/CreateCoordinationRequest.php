<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCoordinationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('flow_type')) {
            $this->merge(['flow_type' => CoordinationFlowTypeEnum::SINGLE->value]);
        }

        if (! $this->has('publish_to_telegram')) {
            $this->merge(['publish_to_telegram' => false]);
        }

        if (! $this->has('allows_suggestions')) {
            $this->merge(['allows_suggestions' => false]);
        }

        if (! $this->has('include_thinking_option')) {
            $this->merge(['include_thinking_option' => false]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('coordination-create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'flow_type' => ['required', Rule::in([
                CoordinationFlowTypeEnum::SINGLE->value,
                CoordinationFlowTypeEnum::EVENT_SCHEDULING->value,
                CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                CoordinationFlowTypeEnum::EVENT_TIME_SELECTION->value,
                CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION->value,
            ])],
            'context_event_id' => ['nullable', 'integer', 'exists:events,id'],
            'question' => ['exclude_unless:flow_type,single', 'required', 'string', 'max:500'],
            'subject_type' => [
                'exclude_unless:flow_type,single',
                'required',
                Rule::in([
                    PollSubjectTypeEnum::TEXT->value,
                    PollSubjectTypeEnum::DATE->value,
                    PollSubjectTypeEnum::TIME->value,
                    PollSubjectTypeEnum::DATETIME->value,
                    PollSubjectTypeEnum::TIME_INTERVAL->value,
                    PollSubjectTypeEnum::VENUE->value,
                ]),
            ],
            'selection_mode' => ['exclude_unless:flow_type,single', 'required', Rule::enum(PollSelectionModeEnum::class)],
            'results_visibility' => ['required', Rule::enum(PollResultsVisibilityEnum::class)],
            'allows_vote_changes' => ['required', 'boolean'],
            'is_anonymous' => ['required', 'boolean'],
            'allows_suggestions' => ['required', 'boolean'],
            'include_thinking_option' => ['required', 'boolean'],
            'publish_to_telegram' => ['required', 'boolean'],
            'telegram_chat_ids' => ['nullable', 'required_if:publish_to_telegram,1', 'array', 'min:1'],
            'telegram_chat_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('telegram_chats', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->where('publishes_coordination', true),
                ),
            ],
            'closes_at' => ['required', 'date', 'after:now'],
            'options' => ['exclude_unless:flow_type,single', 'required', 'array', 'min:2', 'max:20'],
            'step_duration_minutes' => [
                'exclude_unless:flow_type,event_scheduling',
                'required',
                'integer',
                Rule::in([15, 30, 60, 120, 240, 480, 1440]),
            ],
            'date_options' => ['exclude_unless:flow_type,event_scheduling', 'required', 'array', 'min:2', 'max:20'],
            'date_options.*' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'distinct'],
            'time_options' => ['exclude_unless:flow_type,event_scheduling', 'required', 'array', 'min:2', 'max:20'],
            'time_options.*' => ['required', 'array:starts_at,ends_at'],
            'time_options.*.starts_at' => ['required', 'date_format:H:i'],
            'time_options.*.ends_at' => ['required', 'date_format:H:i'],
            'venue_options' => ['exclude_unless:flow_type,event_scheduling', 'required', 'array', 'min:2', 'max:20'],
            'venue_options.*' => ['required', 'integer', 'distinct', 'exists:venues,id'],
            'fixed_venue_id' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('flow_type'), [
                    CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                    CoordinationFlowTypeEnum::EVENT_TIME_SELECTION->value,
                ], true)),
                'nullable',
                'integer',
                'exists:venues,id',
            ],
            'fixed_date' => [
                Rule::requiredIf($this->input('flow_type') === CoordinationFlowTypeEnum::EVENT_TIME_SELECTION->value),
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'fixed_starts_at' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('flow_type'), [
                    CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                    CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION->value,
                ], true)),
                'nullable',
                'date',
                'after:now',
            ],
            'event_duration_minutes' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('flow_type'), [
                    CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                    CoordinationFlowTypeEnum::EVENT_TIME_SELECTION->value,
                    CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION->value,
                ], true)),
                'nullable',
                'integer',
                Rule::in(range(30, 480, 30)),
            ],
            'going_label' => ['exclude_unless:flow_type,event_attendance', 'required', 'string', 'max:255'],
            'not_going_label' => ['exclude_unless:flow_type,event_attendance', 'required', 'string', 'max:255'],
            'thinking_label' => [
                'exclude_unless:flow_type,event_attendance',
                Rule::requiredIf($this->boolean('include_thinking_option')),
                'nullable',
                'string',
                'max:255',
            ],
            'start_time_options' => [
                'exclude_unless:flow_type,event_time_selection',
                'required',
                'array',
                'min:2',
                'max:20',
            ],
            'start_time_options.*' => ['required', 'date_format:H:i', 'distinct'],
            'candidate_venue_ids' => [
                'exclude_unless:flow_type,event_venue_selection',
                'required',
                'array',
                'min:2',
                'max:20',
            ],
            'candidate_venue_ids.*' => ['required', 'integer', 'distinct', 'exists:venues,id'],
            ...$this->optionRules(),
        ];
    }

    /** @return array<string, mixed> */
    private function optionRules(): array
    {
        if ($this->input('flow_type') !== CoordinationFlowTypeEnum::SINGLE->value) {
            return [];
        }

        return match ($this->input('subject_type')) {
            PollSubjectTypeEnum::DATE->value => [
                'options.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            ],
            PollSubjectTypeEnum::TIME->value => [
                'options.*' => ['required', 'date_format:H:i', 'distinct'],
            ],
            PollSubjectTypeEnum::DATETIME->value => [
                'options.*' => ['required', 'date_format:Y-m-d\TH:i', 'distinct'],
            ],
            PollSubjectTypeEnum::TIME_INTERVAL->value => [
                'options.*' => ['required', 'array:starts_at,ends_at'],
                'options.*.starts_at' => ['required', 'date_format:H:i'],
                'options.*.ends_at' => ['required', 'date_format:H:i'],
            ],
            PollSubjectTypeEnum::VENUE->value => [
                'options.*' => ['required', 'integer', 'distinct', 'exists:venues,id'],
            ],
            default => [
                'options.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            ],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'options.min' => 'Добавьте хотя бы два варианта ответа.',
            'options.*.distinct' => 'Варианты ответа не должны повторяться.',
            'closes_at.after' => 'Время завершения должно быть в будущем.',
            'telegram_chat_ids.required_if' => 'Выберите хотя бы один Telegram-чат.',
        ];
    }
}
