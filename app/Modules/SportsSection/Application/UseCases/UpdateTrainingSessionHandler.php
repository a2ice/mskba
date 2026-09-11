<?php

namespace App\Modules\SportsSection\Application\UseCases;

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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTrainingSessionHandler
{
    public function __construct(private SportsSectionAccess $access, private SportsSectionRules $rules) {}

    /** @param array<string, mixed> $data */
    public function handle(SportsSection $section, TrainingSession $session, Actor $actor, array $data): TrainingSession
    {
        $user = $actor->user?->canonical();
        if ($user === null || ! $this->access->allows($user, $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для изменения занятия.');
        }
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = CarbonImmutable::parse($data['ends_at']);
        if (! $startsAt->lessThan($endsAt)) {
            throw new SportsSectionException('Окончание занятия должно быть позже начала.');
        }
        $venueId = isset($data['venue_id']) ? (int) $data['venue_id'] : null;
        $courtId = isset($data['venue_court_id']) ? (int) $data['venue_court_id'] : null;
        $this->rules->assertVenueCourt($venueId, $courtId);
        $eventReference = $session->event_id === null ? null : Event::query()->findOrFail($session->event_id, ['id', 'venue_id']);
        if ($eventReference !== null && ($venueId === null || $courtId === null)) {
            throw new SportsSectionException('У публичного занятия должны быть явно указаны площадка и зал.');
        }

        $result = DB::transaction(function () use ($section, $session, $startsAt, $endsAt, $venueId, $courtId, $data, $eventReference): TrainingSession {
            $event = null;
            if ($eventReference !== null) {
                Venue::query()->whereKey($eventReference->venue_id)->lockForUpdate()->firstOrFail();
                $event = Event::query()->whereKey($eventReference->id)->lockForUpdate()->firstOrFail();
            }
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $session = TrainingSession::query()->where('sports_section_id', $section->id)->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== TrainingSessionStatusEnum::PLANNED) {
                throw new SportsSectionException('Время, место и цену можно менять только у запланированного занятия.');
            }
            if ($session->event_id !== $eventReference?->id) {
                throw new SportsSectionException('Связь занятия с Event изменилась. Обновите страницу.');
            }
            $session->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'venue_id' => $venueId,
                'venue_court_id' => $courtId,
                'price_override_minor' => $data['price_override_minor'] ?? null,
            ]);
            if ($event !== null) {
                $event->forceFill([
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'venue_id' => $venueId,
                    'venue_court_id' => $courtId,
                ])->save();
            }

            return $session->refresh()->load('event');
        });

        if ($eventReference !== null) {
            event(new EventChanged($eventReference->id));
        }

        return $result;
    }
}
