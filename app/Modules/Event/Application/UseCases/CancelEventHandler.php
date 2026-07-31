<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CancelEventHandler
{
    public function __construct(private readonly EventManagementAccess $access) {}

    public function handle(string $identifier, Actor $actor, ?string $reason = null): Event
    {
        $reference = Event::query()->whereRouteIdentifier($identifier)->firstOrFail(['id', 'venue_id']);

        $event = DB::transaction(function () use ($reference, $actor, $reason): Event {
            // Соблюдаем общий порядок блокировок бронирования: venue -> event -> booking.
            Venue::query()->whereKey($reference->venue_id)->lockForUpdate()->firstOrFail();
            $event = Event::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::CANCEL_EVENT);

            if ($event->status === EventStatusEnum::CANCELLED) {
                return $event;
            }

            if ($event->status === EventStatusEnum::COMPLETED || $event->starts_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Начавшееся или завершившееся мероприятие нельзя отменить.');
            }

            $event->booking()->lockForUpdate()->first()?->update([
                'status' => VenueBookingStatusEnum::CANCELLED,
            ]);
            $event->forceFill([
                'status' => EventStatusEnum::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_actor_id' => $actor->id,
                'cancellation_reason' => $reason ?: null,
            ])->save();

            return $event->refresh()->load('booking');
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
