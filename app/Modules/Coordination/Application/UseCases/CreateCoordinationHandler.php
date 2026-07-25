<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\PollOptionValueFactory;
use App\Modules\Coordination\Domain\Enums\CoordinationContextTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateCoordinationHandler
{
    public function __construct(
        private readonly PollOptionValueFactory $optionValues,
        private readonly EventManagementAccess $eventAccess,
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
            $this->eventAccess->assertCanManage($contextEvent, $actor);

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
