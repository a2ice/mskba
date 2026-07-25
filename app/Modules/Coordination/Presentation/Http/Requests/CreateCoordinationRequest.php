<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCoordinationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('publish_to_telegram')) {
            $this->merge(['publish_to_telegram' => false]);
        }

        if (! $this->has('allows_suggestions')) {
            $this->merge(['allows_suggestions' => false]);
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
            'question' => ['required', 'string', 'max:500'],
            'subject_type' => [
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
            'selection_mode' => ['required', Rule::enum(PollSelectionModeEnum::class)],
            'results_visibility' => ['required', Rule::enum(PollResultsVisibilityEnum::class)],
            'allows_vote_changes' => ['required', 'boolean'],
            'is_anonymous' => ['required', 'boolean'],
            'allows_suggestions' => ['required', 'boolean'],
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
            'options' => ['required', 'array', 'min:2', 'max:20'],
            ...$this->optionRules(),
        ];
    }

    /** @return array<string, mixed> */
    private function optionRules(): array
    {
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
