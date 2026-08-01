<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

final class CreateEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'publish_to_telegram' => $this->boolean('publish_to_telegram'),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
            'team_a_id' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                'integer',
                Rule::exists('teams', 'id')->where(fn ($query) => $query
                    ->whereNull('temporary_for_event_id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
                'different:team_b_id',
            ],
            'team_b_id' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                'integer',
                Rule::exists('teams', 'id')->where(fn ($query) => $query
                    ->whereNull('temporary_for_event_id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
                'different:team_a_id',
            ],
            'side_a_size' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                'integer',
                'min:1',
                'max:7',
            ],
            'side_b_size' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                'integer',
                'min:1',
                'max:7',
            ],
            'scoring_type' => [
                'nullable',
                'enum:'.GameScoringTypeEnum::class,
            ],
            'participant_user_ids' => ['nullable', 'array', 'max:499'],
            'participant_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'publish_to_telegram' => ['required', 'boolean'],
            'telegram_chat_ids' => [
                Rule::requiredIf($this->boolean('publish_to_telegram')),
                'array',
                'min:1',
            ],
            'telegram_chat_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('telegram_chats', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->where('publishes_events', true),
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'duration_minutes.required' => 'Выберите длительность мероприятия.',
            'duration_minutes.min' => 'Длительность должна быть больше нуля.',
            'duration_minutes.max' => 'Мероприятие должно завершиться в течение суток.',
            'max_participants.min' => 'Вместимость должна учитывать организатора и хотя бы одного участника.',
            'team_a_id.required' => 'Выберите первую команду.',
            'team_b_id.required' => 'Выберите вторую команду.',
            'team_a_id.different' => 'Для игры нужны две разные команды.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('starts_at')) {
                return;
            }

            try {
                $timezone = (string) config('app.timezone', 'Europe/Moscow');
                $startsAt = CarbonImmutable::parse((string) $this->input('starts_at'), $timezone);
            } catch (Throwable) {
                return;
            }

            if ($startsAt->lessThan($this->minimumStartsAt())) {
                $validator->errors()->add('starts_at', 'Начало должно быть не раньше чем через 15 минут.');
            }

        });
    }

    private function minimumStartsAt(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'))
            ->addMinutes(15)
            ->ceilMinute();
    }
}
