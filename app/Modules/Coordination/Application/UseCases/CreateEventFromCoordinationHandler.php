<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationEventTransition;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Event\Application\UseCases\CreateEventHandler;
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

            $decision = $session->decision()->lockForUpdate()->first();

            if ($decision === null) {
                throw new InvalidArgumentException('У согласования нет принятого решения.');
            }

            $event = $this->createEvent->handle($actor, $data);

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
}
