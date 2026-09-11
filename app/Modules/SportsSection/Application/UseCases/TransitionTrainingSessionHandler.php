<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
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

final readonly class TransitionTrainingSessionHandler
{
    public function __construct(private SportsSectionAccess $access) {}

    public function handle(SportsSection $section, TrainingSession $session, Actor $actor, TrainingSessionStatusEnum $status, ?string $reason = null): TrainingSession
    {
        $user = $actor->user?->canonical();
        if ($user === null || ! $this->access->allows($user, $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для изменения занятия.');
        }
        $reason = trim((string) $reason) ?: null;
        if ($status === TrainingSessionStatusEnum::CANCELLED && $reason === null) {
            throw new SportsSectionException('Укажите причину отмены занятия.');
        }

        $eventReference = $session->event_id === null
            ? null
            : Event::query()->findOrFail($session->event_id, ['id', 'venue_id']);
        $eventId = null;
        $result = DB::transaction(function () use ($section, $session, $actor, $status, $reason, $eventReference, &$eventId): TrainingSession {
            // Единый порядок для связанного Event: venue -> event -> section -> session.
            $event = null;
            if ($eventReference !== null) {
                Venue::query()->whereKey($eventReference->venue_id)->lockForUpdate()->firstOrFail();
                $event = Event::query()->whereKey($eventReference->id)->lockForUpdate()->firstOrFail();
            }
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $session = TrainingSession::query()->where('sports_section_id', $section->id)->lockForUpdate()->findOrFail($session->id);
            if ($session->event_id !== $eventReference?->id) {
                throw new SportsSectionException('Связь занятия с мероприятием изменилась. Обновите страницу.');
            }
            $this->assertTransition($session->status, $status);
            $attributes = ['status' => $status];
            if ($status === TrainingSessionStatusEnum::CONFIRMED) {
                $attributes += [
                    'confirmed_at' => now(),
                    'confirmed_price_minor' => $session->price_override_minor ?? $section->single_session_price_minor,
                    'confirmed_currency' => $section->currency,
                ];
            } elseif ($status === TrainingSessionStatusEnum::COMPLETED) {
                $attributes['completed_at'] = now();
            } elseif ($status === TrainingSessionStatusEnum::CANCELLED) {
                $attributes += ['cancelled_at' => now(), 'cancellation_reason' => $reason];
            }
            $session->update($attributes);
            if ($event !== null) {
                $event->forceFill(match ($status) {
                    TrainingSessionStatusEnum::CONFIRMED => ['status' => EventStatusEnum::PUBLISHED],
                    TrainingSessionStatusEnum::COMPLETED => ['status' => EventStatusEnum::COMPLETED, 'completed_at' => now(), 'completed_by_actor_id' => $actor->id],
                    TrainingSessionStatusEnum::CANCELLED => ['status' => EventStatusEnum::CANCELLED, 'cancelled_at' => now(), 'cancelled_by_actor_id' => $actor->id, 'cancellation_reason' => $reason],
                    TrainingSessionStatusEnum::PLANNED => ['status' => EventStatusEnum::DRAFT],
                })->save();
                $eventId = $event->id;
            }

            return $session->refresh()->load('event');
        });
        if ($eventId !== null) {
            event(new EventChanged($eventId));
        }

        return $result;
    }

    private function assertTransition(TrainingSessionStatusEnum $from, TrainingSessionStatusEnum $to): void
    {
        $allowed = match ($from) {
            TrainingSessionStatusEnum::PLANNED => [TrainingSessionStatusEnum::CONFIRMED, TrainingSessionStatusEnum::CANCELLED],
            TrainingSessionStatusEnum::CONFIRMED => [TrainingSessionStatusEnum::COMPLETED, TrainingSessionStatusEnum::CANCELLED],
            TrainingSessionStatusEnum::COMPLETED, TrainingSessionStatusEnum::CANCELLED => [],
        };
        if (! in_array($to, $allowed, true)) {
            throw new SportsSectionException('Недопустимый переход статуса занятия.');
        }
    }
}
