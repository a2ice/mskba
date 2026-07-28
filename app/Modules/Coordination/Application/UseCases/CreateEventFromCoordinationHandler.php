<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationEventTransition;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Event\Application\UseCases\CreateEventHandler;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateEventFromCoordinationHandler
{
    public function __construct(
        private readonly CoordinationAccess $access,
        private readonly CreateEventHandler $createEvent,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(int $sessionId, Actor $actor, array $data): Event
    {
        return DB::transaction(function () use ($sessionId, $actor, $data): Event {
            /** @var CoordinationSession $session */
            $session = CoordinationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $this->access->canManage($session, $actor)) {
                throw new InvalidArgumentException('Создать мероприятие может только создатель опроса.');
            }

            $existing = CoordinationEventTransition::query()
                ->where('session_id', $session->id)
                ->first();

            if ($existing !== null) {
                $event = Event::withTrashed()->findOrFail($existing->event_id);

                if ($event->trashed()) {
                    throw new InvalidArgumentException('Созданное по этому решению мероприятие было удалено.');
                }

                return $event;
            }

            if ($session->status !== CoordinationSessionStatusEnum::COMPLETED) {
                throw new InvalidArgumentException('Сначала закройте голосование и примите итоговый вариант.');
            }

            $decision = $session->decisions()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($decision === null) {
                throw new InvalidArgumentException('У согласования нет принятого решения.');
            }

            $event = $this->createEvent->handle($actor, $data);
            $this->addSelectedParticipants($session, $event, $actor, $data['participant_user_ids'] ?? []);

            CoordinationEventTransition::query()->create([
                'session_id' => $session->id,
                'decision_id' => $decision->id,
                'event_id' => $event->id,
                'created_by_actor_id' => $actor->id,
                'transitioned_at' => now(),
            ]);

            return $event;
        });
    }

    /** @param array<int, mixed> $userIds */
    private function addSelectedParticipants(
        CoordinationSession $session,
        Event $event,
        Actor $actor,
        array $userIds,
    ): void {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds !== [] && $session->flow_type !== CoordinationFlowTypeEnum::EVENT_ATTENDANCE) {
            throw new InvalidArgumentException('Ручной выбор участников доступен для опроса о намерении прийти.');
        }

        $allowedUserIds = $session->polls()
            ->with('ballots')
            ->get()
            ->flatMap(fn ($poll) => $poll->ballots->pluck('user_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique();

        if (collect($userIds)->diff($allowedUserIds)->isNotEmpty()) {
            throw new InvalidArgumentException('В участники можно добавить только проголосовавших пользователей.');
        }

        $userIds = array_values(array_diff($userIds, [(int) $actor->user_id]));
        $capacity = $event->max_participants;

        if ($capacity !== null && count($userIds) + 1 > $capacity) {
            throw new InvalidArgumentException('Выбрано больше участников, чем допускает вместимость мероприятия.');
        }

        // Database defaults are not always hydrated into a freshly created Eloquent
        // model (notably on SQLite), while the confirmation version is an invariant
        // for every participant row.
        $event->refresh();

        foreach ($userIds as $userId) {
            $event->participants()->updateOrCreate(
                ['user_id' => $userId],
                [
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'joined_at' => now(),
                    'left_at' => null,
                    'confirmation_version' => $event->participation_confirmation_version,
                ],
            );
        }

        if ($userIds !== []) {
            event(new EventChanged($event->id));
        }
    }
}
