<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCoordinationRequest extends FormRequest
{
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
            // The first web slice creates free-text polls only. Other subject
            // types already exist in the domain, but require dedicated typed
            // editors instead of silently accepting a string payload.
            'subject_type' => ['required', Rule::in([PollSubjectTypeEnum::TEXT->value])],
            'selection_mode' => ['required', Rule::enum(PollSelectionModeEnum::class)],
            'results_visibility' => ['required', Rule::enum(PollResultsVisibilityEnum::class)],
            'allows_vote_changes' => ['required', 'boolean'],
            'is_anonymous' => ['required', 'boolean'],
            'closes_at' => ['required', 'date', 'after:now'],
            'options' => ['required', 'array', 'min:2', 'max:20'],
            'options.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'options.min' => 'Добавьте хотя бы два варианта ответа.',
            'options.*.distinct' => 'Варианты ответа не должны повторяться.',
            'closes_at.after' => 'Время завершения должно быть в будущем.',
        ];
    }
}
