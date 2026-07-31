<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\PollOptionValueFactory;
use App\Modules\Coordination\Domain\Enums\CoordinationContextTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\ParticipationIntentEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\ValueObjects\PollOptionValue;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateCoordinationHandler
{
    public function __construct(
        private readonly PollOptionValueFactory $optionValues,
        private readonly EventManagementAccess $eventAccess,
        private readonly VenueEventAvailability $availability,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): CoordinationSession
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Для создания опроса нужен аккаунт пользователя.');
        }

        $flowType = CoordinationFlowTypeEnum::tryFrom((string) ($data['flow_type'] ?? 'single'));

        if ($flowType === null || $flowType === CoordinationFlowTypeEnum::EVENT_CHANGE) {
            throw new InvalidArgumentException('Неизвестный сценарий согласования.');
        }

        if ($flowType === CoordinationFlowTypeEnum::EVENT_SCHEDULING) {
            return $this->createEventSchedulingChain($actor, $data);
        }

        if (in_array($flowType, [
            CoordinationFlowTypeEnum::EVENT_ATTENDANCE,
            CoordinationFlowTypeEnum::EVENT_TIME_SELECTION,
            CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION,
        ], true)) {
            return $this->createEventPoll($actor, $flowType, $data);
        }

        $subjectType = PollSubjectTypeEnum::tryFrom((string) ($data['subject_type'] ?? ''));

        if ($subjectType === null) {
            throw new InvalidArgumentException('Неизвестный тип вариантов.');
        }

        $selectionMode = PollSelectionModeEnum::tryFrom((string) ($data['selection_mode'] ?? ''));

        if ($selectionMode === null) {
            throw new InvalidArgumentException('Неизвестный режим выбора.');
        }

        $closesAt = CarbonImmutable::parse((string) ($data['closes_at'] ?? ''));

        if ($closesAt->isPast()) {
            throw new InvalidArgumentException('Время завершения должно быть в будущем.');
        }

        $options = $this->optionValues->many($subjectType, $data['options'] ?? []);

        return DB::transaction(function () use (
            $actor,
            $closesAt,
            $data,
            $options,
            $selectionMode,
            $subjectType,
        ): CoordinationSession {
            $session = CoordinationSession::query()->create([
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => CoordinationSessionStatusEnum::OPEN,
                'flow_type' => CoordinationFlowTypeEnum::SINGLE,
            ]);

            $poll = $session->polls()->create([
                'question' => $data['question'],
                'subject_type' => $subjectType,
                'selection_mode' => $selectionMode,
                'results_visibility' => $data['results_visibility'] ?? PollResultsVisibilityEnum::AFTER_VOTE,
                'status' => PollStatusEnum::OPEN,
                'allows_suggestions' => (bool) ($data['allows_suggestions'] ?? false),
                'allows_vote_changes' => (bool) $data['allows_vote_changes'],
                'is_anonymous' => (bool) $data['is_anonymous'],
                'closes_at' => $closesAt,
                'step_order' => 1,
                'voting_duration_minutes' => max(15, $closesAt->diffInMinutes(now())),
            ]);

            foreach ($options as $position => $option) {
                $poll->options()->create([
                    'label' => $option->label,
                    'value' => $option->value,
                    'sort_order' => $position,
                    'is_active' => true,
                ]);
            }

            return $session->load(['polls.options', 'organizerActor.user']);
        });
    }

    /** @param array<string, mixed> $data */
    private function createEventPoll(
        Actor $actor,
        CoordinationFlowTypeEnum $flowType,
        array $data,
    ): CoordinationSession {
        $closesAt = CarbonImmutable::parse((string) ($data['closes_at'] ?? ''));

        if ($closesAt->isPast()) {
            throw new InvalidArgumentException('Время завершения должно быть в будущем.');
        }

        $duration = isset($data['event_duration_minutes']) && $data['event_duration_minutes'] !== ''
            ? (int) $data['event_duration_minutes']
            : null;
        $configuration = [
            'duration_minutes' => $duration,
            'automatic_duration' => $duration === null,
        ];
        $question = '';
        $subjectType = PollSubjectTypeEnum::TEXT;
        $options = [];

        if ($flowType === CoordinationFlowTypeEnum::EVENT_ATTENDANCE) {
            $venue = $this->availableVenue((int) $data['fixed_venue_id']);
            $startsAt = $this->parseVenueDateTime($venue, (string) $data['fixed_starts_at']);
            $endsAt = $this->availability->resolveEndsAt($venue, $startsAt, $duration);
            $configuration['duration_minutes'] = (int) $startsAt->diffInMinutes($endsAt);
            $configuration += ['venue_id' => $venue->id, 'starts_at' => $startsAt->toIso8601String()];
            // Название опроса достаточно описывает сценарий сбора участников.
            // Отдельный вопрос можно вернуть позже как редактируемое поле.
            $question = '';
            $subjectType = PollSubjectTypeEnum::PARTICIPATION;
            $options = [
                PollOptionValue::participation(ParticipationIntentEnum::GOING, (string) $data['going_label']),
                PollOptionValue::participation(ParticipationIntentEnum::NOT_GOING, (string) $data['not_going_label']),
            ];

            if ((bool) ($data['include_thinking_option'] ?? false)) {
                $options[] = PollOptionValue::participation(
                    ParticipationIntentEnum::THINKING,
                    (string) $data['thinking_label'],
                );
            }
        } elseif ($flowType === CoordinationFlowTypeEnum::EVENT_TIME_SELECTION) {
            $venue = $this->availableVenue((int) $data['fixed_venue_id']);
            $configuration += ['venue_id' => $venue->id, 'date' => (string) $data['fixed_date']];
            $question = 'Во сколько начинаем?';
            $subjectType = PollSubjectTypeEnum::TIME;
            $options = $this->optionValues->many($subjectType, $data['start_time_options'] ?? []);

            foreach ($options as $option) {
                $startsAt = $this->parseVenueDateTime(
                    $venue,
                    $configuration['date'].' '.$option->value['time'],
                );
                $this->availability->resolveEndsAt($venue, $startsAt, $duration);
            }
        } else {
            $startsAt = CarbonImmutable::parse(
                (string) $data['fixed_starts_at'],
                (string) config('app.timezone', 'Europe/Moscow'),
            )->utc();
            $question = 'На какой площадке проводим мероприятие?';
            $subjectType = PollSubjectTypeEnum::VENUE;
            $options = $this->optionValues->many($subjectType, $data['candidate_venue_ids'] ?? []);
            $configuration += ['starts_at' => $startsAt->toIso8601String()];

            foreach ($options as $option) {
                $venue = $this->availableVenue((int) $option->value['venue_id']);
                $this->availability->resolveEndsAt($venue, $startsAt, $duration);
            }
        }

        return DB::transaction(function () use (
            $actor,
            $closesAt,
            $configuration,
            $data,
            $flowType,
            $options,
            $question,
            $subjectType,
        ): CoordinationSession {
            $session = CoordinationSession::query()->create([
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => CoordinationSessionStatusEnum::OPEN,
                'flow_type' => $flowType,
            ]);
            $poll = $session->polls()->create([
                'question' => $question,
                'subject_type' => $subjectType,
                'selection_mode' => PollSelectionModeEnum::SINGLE,
                'results_visibility' => $data['results_visibility'] ?? PollResultsVisibilityEnum::AFTER_VOTE,
                'status' => PollStatusEnum::OPEN,
                'allows_suggestions' => (bool) ($data['allows_suggestions'] ?? false),
                'allows_vote_changes' => (bool) $data['allows_vote_changes'],
                'is_anonymous' => false,
                'closes_at' => $closesAt,
                'step_order' => 1,
                'voting_duration_minutes' => max(15, $closesAt->diffInMinutes(now())),
                'configuration' => $configuration,
            ]);

            foreach ($options as $position => $option) {
                $poll->options()->create([
                    'label' => $option->label,
                    'value' => $option->value,
                    'sort_order' => $position,
                    'is_active' => true,
                ]);
            }

            return $session->load(['polls.options', 'organizerActor.user']);
        });
    }

    private function availableVenue(int $venueId): Venue
    {
        $venue = Venue::query()
            ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
            ->find($venueId);

        if ($venue === null) {
            throw new InvalidArgumentException('Выберите доступную площадку.');
        }

        return $venue;
    }

    private function parseVenueDateTime(Venue $venue, string $value): CarbonImmutable
    {
        $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');

        return CarbonImmutable::parse($value, $timezone)->utc();
    }

    /** @param array<string, mixed> $data */
    private function createEventSchedulingChain(Actor $actor, array $data): CoordinationSession
    {
        $closesAt = CarbonImmutable::parse((string) ($data['closes_at'] ?? ''));

        if ($closesAt->isPast()) {
            throw new InvalidArgumentException('Время завершения должно быть в будущем.');
        }

        $duration = max(15, (int) ($data['step_duration_minutes'] ?? 60));
        $dates = $this->optionValues->many(PollSubjectTypeEnum::DATE, $data['date_options'] ?? []);
        $times = $this->optionValues->many(PollSubjectTypeEnum::TIME_INTERVAL, $data['time_options'] ?? []);
        $venues = $this->optionValues->many(PollSubjectTypeEnum::VENUE, $data['venue_options'] ?? []);

        $contextEvent = null;
        if (isset($data['context_event_id'])) {
            $contextEvent = Event::query()->with('booking')->findOrFail((int) $data['context_event_id']);
            $this->eventAccess->assertAllows(
                $contextEvent,
                $actor,
                EventResponsibilityPermissionEnum::UPDATE_EVENT,
            );

            if ($contextEvent->status !== EventStatusEnum::PUBLISHED || $contextEvent->starts_at->isPast()) {
                throw new InvalidArgumentException('Согласовать перенос можно только для предстоящего опубликованного мероприятия.');
            }
        }

        return DB::transaction(function () use (
            $actor,
            $closesAt,
            $contextEvent,
            $data,
            $dates,
            $duration,
            $times,
            $venues,
        ): CoordinationSession {
            $session = CoordinationSession::query()->create([
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => CoordinationSessionStatusEnum::OPEN,
                'flow_type' => $contextEvent
                    ? CoordinationFlowTypeEnum::EVENT_CHANGE
                    : CoordinationFlowTypeEnum::EVENT_SCHEDULING,
                'context_type' => $contextEvent ? CoordinationContextTypeEnum::EVENT : null,
                'context_id' => $contextEvent?->id,
            ]);

            $common = [
                'selection_mode' => PollSelectionModeEnum::SINGLE,
                'results_visibility' => $data['results_visibility'] ?? PollResultsVisibilityEnum::AFTER_VOTE,
                'allows_suggestions' => false,
                'allows_vote_changes' => (bool) $data['allows_vote_changes'],
                'is_anonymous' => (bool) $data['is_anonymous'],
                'voting_duration_minutes' => $duration,
            ];
            $datePoll = $session->polls()->create([
                ...$common,
                'step_order' => 1,
                'question' => 'В какой день проводим мероприятие?',
                'subject_type' => PollSubjectTypeEnum::DATE,
                'status' => PollStatusEnum::OPEN,
                'closes_at' => $closesAt,
            ]);
            $timePoll = $session->polls()->create([
                ...$common,
                'step_order' => 2,
                'depends_on_poll_id' => $datePoll->id,
                'question' => 'Какое время выбираем?',
                'subject_type' => PollSubjectTypeEnum::TIME_INTERVAL,
                'status' => PollStatusEnum::DRAFT,
                'closes_at' => $closesAt,
            ]);
            $venuePoll = $session->polls()->create([
                ...$common,
                'step_order' => 3,
                'depends_on_poll_id' => $timePoll->id,
                'question' => 'На какой площадке проводим мероприятие?',
                'subject_type' => PollSubjectTypeEnum::VENUE,
                'status' => PollStatusEnum::DRAFT,
                'closes_at' => $closesAt,
                'configuration' => [
                    'candidate_venue_ids' => array_column(array_map(
                        static fn ($option): array => $option->value,
                        $venues,
                    ), 'venue_id'),
                    'excluded_booking_id' => $contextEvent?->booking?->id,
                ],
            ]);

            foreach ([[$datePoll, $dates], [$timePoll, $times], [$venuePoll, $venues]] as [$poll, $options]) {
                foreach ($options as $position => $option) {
                    $poll->options()->create([
                        'label' => $option->label,
                        'value' => $option->value,
                        'sort_order' => $position,
                        'is_active' => $poll->id !== $venuePoll->id,
                    ]);
                }
            }

            return $session->load(['polls.options', 'organizerActor.user']);
        });
    }
}
