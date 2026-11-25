<?php

namespace App\Modules\PlayInterest\Application\Listeners;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Application\Services\EventInterestScheduleMatcher;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

final class NotifyMatchingEventSubscribers implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(
        private readonly RecommendVenuesForIntent $venueMatcher,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function handle(EventChanged $changed): void
    {
        $event = Event::query()->with(['venue', 'primaryGame'])->find($changed->eventId);
        if ($event === null || $event->venue === null
            || $event->status !== EventStatusEnum::PUBLISHED
            || $event->visibility !== EventVisibilityEnum::PUBLIC
            || $event->starts_at === null
            || $event->ends_at === null
            || $event->starts_at->lessThanOrEqualTo(now())
            || ! in_array($event->type, [EventTypeEnum::GAME, EventTypeEnum::TRAINING, EventTypeEnum::GAME_TRAINING], true)) {
            return;
        }

        // A public game can still reject new player applications.
        if ($event->type === EventTypeEnum::GAME
            && ($event->primaryGame === null || ! $event->primaryGame->accepts_applications
                || $event->primaryGame->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT)) {
            return;
        }

        $venue = $event->venue;
        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $start = $event->starts_at->setTimezone($timezone);
        // A single event may offer several non-binding times. Match interests
        // against every proposed interval, not only the first one displayed.
        $candidateTimes = app(EventInterestScheduleMatcher::class)->candidateTimes($event, $timezone);
        $organizerId = $event->organizerActor?->user?->canonical()->id;
        $targets = [];

        // Search is a background projection; the subscription remains the source
        // of truth. A saved favorite now explicitly creates a default PlayIntent.
        $intents = PlayIntent::query()
            ->with('user')
            ->where('status', 'active')
            ->where('notify_matches', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->where('venue_id', $venue->id)
                ->orWhereNull('venue_id')->orWhere('include_similar_venues', true))
            ->orderBy('id')->lazyById(200);

        foreach ($intents as $intent) {
            $user = $intent->user?->canonical();
            if ($user === null || ! $user->isConfirmed() || $user->isBlocked()
                || $user->trashed() || (int) $user->id === (int) $organizerId
                || ! $this->venueMatcher->matchesVenue($intent, $venue)
                || ! collect($candidateTimes)->contains(fn (array $window): bool =>
                    $this->matchesSchedule($intent, $event, $window[0], $window[1]))) {
                continue;
            }

            $channels = array_values(array_intersect(
                ['in_app', 'email', 'telegram'],
                (array) ($intent->game_delivery_channels ?? ['in_app']),
            ));
            if ($channels === []) {
                continue;
            }

            $id = (int) $user->id;
            $targets[$id] = array_values(array_unique(array_merge($targets[$id] ?? [], $channels)));
        }

        foreach ($targets as $userId => $channels) {
            DB::transaction(function () use ($event, $venue, $start, $timezone, $userId, $channels): void {
                // Unique(event,user) makes queued retries, multiple matching
                // subscriptions and RSVP-triggered EventChanged idempotent.
                $created = DB::table('event_interest_notification_deliveries')->insertOrIgnore([
                    'event_id' => $event->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
                if ($created === 0) {
                    return;
                }

                $this->notifications->handle(new CreateUserNotificationDTO(
                    userId: $userId,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: 'Новое мероприятие по вашим интересам',
                    body: '«'.$event->title.'» на площадке «'.$venue->name.'» — '.$start->format('d.m.Y H:i').'.'
                        .(!$event->time_confirmed ? ' Время предварительное.' : '')
                        .($event->booking_deferred && $event->booking_id === null ? ' Бронирование не создано.' : '')
                        .(count($event->proposed_time_options ?? []) > 1 ? ' Есть альтернативные варианты времени.' : ''),
                    actionUrl: route('events.show', $event->routeIdentifier(), false),
                    actionText: 'Открыть мероприятие',
                    payload: [
                        'source' => 'play_interest.event',
                        'delivery_category' => 'general',
                        'delivery_channels' => $channels,
                        'event_id' => $event->id,
                        'venue_id' => $venue->id,
                    ],
                ), deferEventUntilCommit: true);
            }, 3);
        }
    }

    public function matchesSchedule(
        PlayIntent $intent,
        Event $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): bool {
        return app(EventInterestScheduleMatcher::class)->matchesSchedule($intent, $event, $start, $end);
    }
}
