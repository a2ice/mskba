<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final readonly class PublishTrainingSessionEventHandler
{
    public function __construct(
        private SportsSectionAccess $access,
        private SportsSectionRules $rules,
    ) {}

    public function handle(SportsSection $section, TrainingSession $session, Actor $actor): TrainingSession
    {
        $user = $actor->user?->canonical();
        if ($user === null || ! $this->access->allows($user, $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для публикации занятия.');
        }
        if (! in_array($session->status, [TrainingSessionStatusEnum::PLANNED, TrainingSessionStatusEnum::CONFIRMED], true)) {
            throw new SportsSectionException('Опубликовать можно только запланированное или подтверждённое занятие.');
        }
        if ($session->venue_id === null || $session->venue_court_id === null) {
            throw new SportsSectionException('Чтобы опубликовать занятие, укажите площадку и зал.');
        }
        $this->rules->assertVenueCourt($session->venue_id, $session->venue_court_id);

        $eventId = null;
        $result = DB::transaction(function () use ($section, $session, $actor, &$eventId): TrainingSession {
            Venue::query()->whereKey($session->venue_id)->lockForUpdate()->firstOrFail();
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $session = TrainingSession::query()
                ->where('sports_section_id', $section->id)
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($session->event_id !== null) {
                return $session->load('event');
            }
            if (! in_array($session->status, [TrainingSessionStatusEnum::PLANNED, TrainingSessionStatusEnum::CONFIRMED], true)) {
                throw new SportsSectionException('Статус занятия изменился. Обновите страницу.');
            }
            if ($session->venue_id === null || $session->venue_court_id === null) {
                throw new SportsSectionException('Чтобы опубликовать занятие, укажите площадку и зал.');
            }

            $event = Event::query()->create([
                'venue_id' => $session->venue_id,
                'venue_court_id' => $session->venue_court_id,
                'organizer_actor_id' => $actor->id,
                'title' => 'Тренировка · '.$section->name,
                'alias' => 'training-session-'.$session->id,
                'type' => EventTypeEnum::TRAINING,
                'status' => $session->status === TrainingSessionStatusEnum::CONFIRMED
                    ? EventStatusEnum::PUBLISHED
                    : EventStatusEnum::DRAFT,
                'visibility' => EventVisibilityEnum::PUBLIC,
                'description' => $section->description,
                'starts_at' => $session->starts_at,
                'ends_at' => $session->ends_at,
                'max_participants' => null,
            ]);

            $event->participants()->create([
                'user_id' => $actor->user_id,
                'role' => EventParticipantRoleEnum::ORGANIZER,
                'status' => EventParticipantStatusEnum::CONFIRMED,
                'joined_at' => now(),
            ]);

            $session->update(['event_id' => $event->id]);
            $eventId = $event->id;

            return $session->refresh()->load('event');
        });

        if ($eventId !== null) {
            event(new EventChanged($eventId));
        }

        return $result;
    }
}
