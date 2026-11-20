<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class EventDeletionService
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function isOwner(Event $event, User $user): bool
    {
        $user = $user->canonical();

        return ! $user->isBlocked() && ! $user->trashed()
            && $event->organizerActor()->whereIn('user_id', $user->identityIds())->exists();
    }

    public function latest(Event $event): ?object
    {
        return DB::table('event_lifecycle_changes')->where('event_id', $event->id)->latest('id')->first();
    }

    public function mayReadDeleted(Event $event, User $user): bool
    {
        if ($this->isOwner($event, $user)) {
            return true;
        }

        $user = $user->canonical();
        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }

        return $event->participants()->whereIn('user_id', $user->identityIds())
            ->whereIn('status', [EventParticipantStatusEnum::CONFIRMED->value, EventParticipantStatusEnum::TENTATIVE->value])
            ->exists();
    }

    public function change(string $identifier, User $user, bool $restore, string $reason): Event
    {
        $user = $user->canonical();
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину длиной от 10 до 2000 символов.']);
        }

        $reference = Event::withTrashed()->whereRouteIdentifier($identifier)->firstOrFail();

        return DB::transaction(function () use ($reference, $user, $restore, $reason): Event {
            // Follow the booking lock order: venue -> event -> related rows.
            Venue::query()->whereKey($reference->venue_id)->lockForUpdate()->firstOrFail();
            $event = Event::withTrashed()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->isOwner($event, $user), 403);
            $previous = $this->latest($event);

            if ($event->trashed()) {
                abort_unless($previous?->action === 'deleted', 404);
            }
            if ($event->trashed() !== $restore) {
                return $event;
            }

            $snapshot = null;
            if ($restore) {
                $snapshot = json_decode($previous->snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                $event->restore();
                $event->games()->withTrashed()->whereIn('id', $snapshot['games'] ?? [])
                    ->whereNotNull('deleted_at')->get()->each->restore();
                // Only restore the open (non-binding) interests withdrawn by this action.
                $event->venueInterests()->whereIn('id', $snapshot['interests'] ?? [])
                    ->where('status', 'withdrawn')->update(['status' => 'open']);
            } else {
                // Never silently cancel a rental, refund, or an external booking request.
                if ($event->booking_id !== null || $event->booking()->exists()
                    || $event->venueInterests()->whereHas('booking')->exists()
                    || $event->venueInterests()->where('status', 'booking_requested')->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Сначала урегулируйте связанную заявку или бронирование.']);
                }
                if ($event->trainingSession()->exists()
                    || $event->games()->where(function ($query): void {
                        $query->where('status', '!=', GameStatusEnum::SCHEDULED->value)
                            ->orWhereHas('tournamentMatch')->orWhereHas('playerStatistics')
                            ->orWhereHas('sides', fn ($side) => $side->where('score', '>', 0));
                    })->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Связанное занятие, турнир или игра с результатами требуют отдельного завершения.']);
                }
                $games = $event->games()->lockForUpdate()->get();
                $interests = $event->venueInterests()->where('status', 'open')->lockForUpdate()->pluck('id');
                $snapshot = ['games' => $games->modelKeys(), 'interests' => $interests->all()];
                $games->each->delete();
                $event->venueInterests()->whereIn('id', $interests)->update(['status' => 'withdrawn']);
                $event->delete();
            }

            $changeId = DB::table('event_lifecycle_changes')->insertGetId([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'action' => $restore ? 'restored' : 'deleted',
                'reason' => $reason,
                'snapshot' => $snapshot === null ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $this->notify($event, $user, $restore, $reason, $changeId);

            return $event;
        });
    }

    private function notify(Event $event, User $owner, bool $restore, string $reason, int $changeId): void
    {
        // Use the latest response across linked identities; never notify the same person twice.
        $event->participants()->with('user')->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->filter(fn ($participant) => $participant->user !== null)
            ->groupBy(fn ($participant) => $participant->user->canonical()->id)
            ->each(function ($responses) use ($event, $owner, $restore, $reason, $changeId): void {
                $participant = $responses->first();
                $recipient = $participant->user->canonical();
                if ($recipient->id === $owner->id || $recipient->trashed()
                    || ! in_array($participant->status, [EventParticipantStatusEnum::CONFIRMED, EventParticipantStatusEnum::TENTATIVE], true)) {
                    return;
                }

                $title = $restore ? 'Мероприятие восстановлено' : 'Мероприятие удалено';
                $this->notifications->handle(new CreateUserNotificationDTO(
                    userId: $recipient->id,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: $title,
                    body: $title.': «'.$event->title.'». Причина: '.$reason,
                    actionUrl: route('events.lifecycle.show', $event->routeIdentifier()),
                    actionText: 'Посмотреть мероприятие',
                    payload: [
                        'source' => 'event.lifecycle',
                        'delivery_category' => 'system',
                        'delivery_channels' => ['in_app'],
                        'event_id' => $event->id,
                        'event_lifecycle_change_id' => $changeId,
                    ],
                ), deferEventUntilCommit: true);
            });
    }
}
