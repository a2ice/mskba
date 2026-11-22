<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;

final class EventTimeComment
{
    public const DEFAULT_COMMENT = 'Дата и время мероприятия предварительные и могут измениться. Организатор уточнит их дополнительно.';

    public static function rules(): array
    {
        return [
            'time_comment' => ['nullable', 'string', 'max:2000'],
            'time_confirmed' => ['sometimes', 'boolean'],
            'save_time_comment_default' => ['sometimes', 'boolean'],
        ];
    }

    public static function defaultComment(?User $user): string
    {
        return $user?->canonical()->default_event_time_comment ?? self::DEFAULT_COMMENT;
    }

    public function attributes(Actor $actor, array $data, ?Event $event = null): array
    {
        $attributes = [];
        if (array_key_exists('time_confirmed', $data)) {
            $attributes['time_confirmed'] = filter_var($data['time_confirmed'], FILTER_VALIDATE_BOOLEAN);
        }
        if (! array_key_exists('time_comment', $data) && $event !== null) {
            return $attributes;
        }

        $comment = array_key_exists('time_comment', $data)
            ? trim((string) $data['time_comment'])
            : self::defaultComment($actor->user);

        if (filter_var($data['save_time_comment_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $actor->user?->canonical()->forceFill(['default_event_time_comment' => $comment])->save();
        }

        return [...$attributes, 'time_comment' => $comment];
    }

    // Called only for an explicitly opted-in, confirmed rental while the event is locked.
    public function confirmFromRental(Event $event, CarbonInterface $start, CarbonInterface $end, Actor $actor): void
    {
        if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $event->hasEnded() || $end->isPast()) {
            return;
        }
        $changed = ! $event->starts_at?->equalTo($start) || ! $event->ends_at?->equalTo($end);
        $previousStart = $event->starts_at;
        $previousEnd = $event->ends_at;
        $databaseTimezone = DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC');
        $localTimezone = $event->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $event->forceFill([
            'starts_at' => $start->copy()->setTimezone($databaseTimezone),
            'ends_at' => $end->copy()->setTimezone($databaseTimezone),
            'time_confirmed' => true,
            'status' => $event->status === EventStatusEnum::DRAFT ? EventStatusEnum::PUBLISHED : $event->status,
            'proposed_time_options' => $event->proposed_time_options !== null
                ? [['starts_at' => $start->copy()->setTimezone($localTimezone)->format('Y-m-d\TH:i'),
                    'duration_minutes' => (int) $start->diffInMinutes($end)]]
                : null,
            'participation_confirmation_version' => $event->participation_confirmation_version + (int) ($changed && $previousStart !== null),
        ])->save();

        if ($changed && $previousStart !== null) {
            $event->venueRevisions()->create([
                'revision' => (int) $event->venueRevisions()->max('revision') + 1,
                'changed_by_actor_id' => $actor->id,
                'previous_venue_id' => $event->venue_id, 'next_venue_id' => $event->venue_id,
                'previous_court_id' => $event->venue_court_id, 'next_court_id' => $event->venue_court_id,
                'previous_scope' => $event->planned_booking_scope?->value,
                'next_scope' => $event->planned_booking_scope?->value,
                'previous_starts_at' => $previousStart, 'previous_ends_at' => $previousEnd,
                'next_starts_at' => $event->starts_at, 'next_ends_at' => $event->ends_at,
                'reason' => 'Время обновлено по подтверждённой аренде.',
            ]);
            $event->participants()->where('role', EventParticipantRoleEnum::ORGANIZER->value)
                ->update(['confirmation_version' => $event->participation_confirmation_version]);
        }
        DB::afterCommit(static fn () => event(new EventChanged($event->id)));
    }

    public static function isPreliminary(Event $event): bool
    {
        return $event->starts_at !== null
            && ! $event->hasEnded()
            && $event->status->value !== 'cancelled'
            && ! $event->time_confirmed;
    }
}
