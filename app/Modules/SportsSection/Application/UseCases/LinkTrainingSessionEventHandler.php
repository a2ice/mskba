<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final readonly class LinkTrainingSessionEventHandler
{
    public function __construct(private SportsSectionAccess $sections, private EventManagementAccess $events) {}

    public function handle(SportsSection $section, TrainingSession $session, Event $event, Actor $actor): TrainingSession
    {
        $user = $actor->user?->canonical();
        if ($user === null || ! $this->sections->allows($user, $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для связи занятия с мероприятием.');
        }
        $this->events->assertAllows($event, $actor, EventResponsibilityPermissionEnum::UPDATE_EVENT);

        $result = DB::transaction(function () use ($section, $session, $event): TrainingSession {
            Venue::query()->whereKey($event->venue_id)->lockForUpdate()->firstOrFail();
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $session = TrainingSession::query()->where('sports_section_id', $section->id)->lockForUpdate()->findOrFail($session->id);
            if ($event->type !== EventTypeEnum::TRAINING) {
                throw new SportsSectionException('С занятием можно связать только мероприятие типа «Тренировка».');
            }
            if ($event->booking_id !== null) {
                throw new SportsSectionException('Мероприятие с управляемой бронью нельзя связать с занятием.');
            }
            if (TrainingSession::query()->where('event_id', $event->id)->where('id', '!=', $session->id)->exists()) {
                throw new SportsSectionException('Это мероприятие уже связано с другим занятием.');
            }
            if ($session->venue_id !== $event->venue_id || $session->venue_court_id !== $event->venue_court_id
                || ! $session->starts_at->equalTo($event->starts_at) || ! $session->ends_at->equalTo($event->ends_at)) {
                throw new SportsSectionException('Время, площадка и зал занятия должны совпадать с мероприятием до связывания.');
            }
            $session->update(['event_id' => $event->id]);
            $event->forceFill(match ($session->status) {
                TrainingSessionStatusEnum::PLANNED => ['status' => EventStatusEnum::DRAFT],
                TrainingSessionStatusEnum::CONFIRMED => ['status' => EventStatusEnum::PUBLISHED],
                TrainingSessionStatusEnum::COMPLETED => ['status' => EventStatusEnum::COMPLETED, 'completed_at' => $session->completed_at],
                TrainingSessionStatusEnum::CANCELLED => ['status' => EventStatusEnum::CANCELLED, 'cancelled_at' => $session->cancelled_at, 'cancellation_reason' => $session->cancellation_reason],
            })->save();

            return $session->refresh()->load('event');
        });

        event(new EventChanged($event->id));

        return $result;
    }
}
