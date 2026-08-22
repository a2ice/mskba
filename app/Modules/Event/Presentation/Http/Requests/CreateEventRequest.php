<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

final class CreateEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $prepared = [
            'publish_to_telegram' => $this->boolean('publish_to_telegram'),
            'game_accepts_applications' => $this->input('type') === EventTypeEnum::GAME->value
                ? ($this->has('game_accepts_applications') ? $this->boolean('game_accepts_applications') : true)
                : false,
        ];
        if ($this->input('type') === EventTypeEnum::GAME->value && ! $this->filled('game_recruitment_mode')) {
            $prepared['game_recruitment_mode'] = GameRecruitmentModeEnum::PREFORMED_TEAMS->value;
        }
        if ($this->input('type') === EventTypeEnum::GAME->value && ! $this->filled('game_format')) {
            $sizeA = (int) $this->input('side_a_size', 3);
            $sizeB = (int) $this->input('side_b_size', 3);
            $scoring = (string) $this->input('scoring_type', GameScoringTypeEnum::STREETBALL->value);
            $prepared['game_format'] = match ([$sizeA, $sizeB, $scoring]) {
                [5, 5, GameScoringTypeEnum::BASKETBALL->value] => GameFormatEnum::BASKETBALL_5X5->value,
                [3, 3, GameScoringTypeEnum::STREETBALL->value] => GameFormatEnum::STREETBALL_3X3->value,
                [1, 1, GameScoringTypeEnum::STREETBALL->value] => GameFormatEnum::STREETBALL_1X1->value,
                default => GameFormatEnum::CUSTOM->value,
            };
        }
        if ($this->input('type') === EventTypeEnum::GAME->value && ! $this->filled('timing_mode')) {
            $prepared['timing_mode'] = ($prepared['game_format'] ?? $this->input('game_format')) === GameFormatEnum::BASKETBALL_5X5->value
                ? GameTimingModeEnum::PERIODS->value
                : GameTimingModeEnum::WHOLE_GAME->value;
        }

        $this->merge($prepared);
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
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
            'game_recruitment_mode' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                Rule::enum(GameRecruitmentModeEnum::class),
            ],
            'game_accepts_applications' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'boolean',
            ],
            'team_a_id' => [
                'nullable',
                'integer',
                Rule::exists('teams', 'id')->where(fn ($query) => $query
                    ->whereNull('temporary_for_event_id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
                'different:team_b_id',
            ],
            'team_b_id' => [
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
            'scoring_type' => ['nullable', Rule::enum(GameScoringTypeEnum::class)],
            'game_format' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                Rule::enum(GameFormatEnum::class),
            ],
            'timing_mode' => [
                Rule::requiredIf($this->input('type') === EventTypeEnum::GAME->value),
                'nullable',
                Rule::enum(GameTimingModeEnum::class),
            ],
            'periods_count' => [
                Rule::requiredIf($this->input('timing_mode') === GameTimingModeEnum::PERIODS->value),
                'nullable',
                'integer',
                Rule::in([2, 4]),
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
            'team_a_id.different' => 'Одна команда не может занимать обе стороны игры.',
            'team_b_id.different' => 'Одна команда не может занимать обе стороны игры.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type') === EventTypeEnum::GAME->value) {
                $recruitmentMode = GameRecruitmentModeEnum::tryFrom((string) $this->input('game_recruitment_mode'));
                if ($recruitmentMode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT
                    && ($this->filled('team_a_id') || $this->filled('team_b_id'))) {
                    $validator->errors()->add(
                        'game_recruitment_mode',
                        'В режиме набора отдельных игроков готовые команды при создании не указываются.',
                    );
                }
                if ($recruitmentMode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT
                    && (int) $this->input('side_a_size') !== (int) $this->input('side_b_size')) {
                    $validator->errors()->add(
                        'side_b_size',
                        'Для balanced-набора размер обеих сторон должен совпадать.',
                    );
                }
            }

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
                $validator->errors()->add('starts_at', 'Начало не может быть раньше текущего времени.');
            }
        });
    }

    private function minimumStartsAt(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'))
            ->subMinute()
            ->startOfMinute();
    }
}
