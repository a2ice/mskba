<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationContextTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ApplyEventCoordinationHandler
{
    public function __construct(
        private readonly CoordinationAccess $access,
        private readonly UpdateEventHandler $updateEvent,
    ) {}

    public function handle(int $sessionId, Actor $actor): Event
    {
        // Сначала читаем завершённое согласование, затем отдельный use case меняет
        // мероприятие со своим порядком блокировок venue -> event -> booking.
        $session = CoordinationSession::query()
            ->with(['decisions.poll', 'decisions.option'])
            ->findOrFail($sessionId);

        if (! $this->access->canManage($session, $actor)) {
            throw new InvalidArgumentException('Применить решение может только создатель согласования.');
        }

        if ($session->status !== CoordinationSessionStatusEnum::COMPLETED
            || $session->context_type !== CoordinationContextTypeEnum::EVENT
            || $session->context_id === null) {
            throw new InvalidArgumentException('Это согласование ещё нельзя применить к мероприятию.');
        }

        $event = Event::query()->findOrFail($session->context_id);
        $byType = $session->decisions->keyBy(
            fn ($decision): string => $decision->poll->subject_type->value,
        );
        $date = $byType->get(PollSubjectTypeEnum::DATE->value)?->option?->value['date'] ?? null;
        $interval = $byType->get(PollSubjectTypeEnum::TIME_INTERVAL->value)?->option?->value ?? null;
        $venueId = $byType->get(PollSubjectTypeEnum::VENUE->value)?->option?->value['venue_id'] ?? null;

        if (! is_string($date) || ! is_array($interval) || ! is_numeric($venueId)) {
            throw new InvalidArgumentException('В цепочке не согласованы дата, время или площадка.');
        }

        $start = CarbonImmutable::parse($date.' '.($interval['starts_at'] ?? ''));
        $end = CarbonImmutable::parse($date.' '.($interval['ends_at'] ?? ''));
        $duration = (int) $start->diffInMinutes($end);

        return $this->updateEvent->handle($event->routeIdentifier(), $actor, [
            'venue_id' => (int) $venueId,
            'title' => $event->title,
            'type' => $event->type->value,
            'visibility' => $event->visibility->value,
            'description' => $event->description,
            'starts_at' => $date.'T'.($interval['starts_at'] ?? ''),
            'duration_minutes' => $duration,
            'max_participants' => $event->max_participants,
        ]);
    }
}
