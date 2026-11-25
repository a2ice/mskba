<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\EventInterestScheduleMatcher;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use Illuminate\Database\Eloquent\Builder;

final readonly class MyInterestsProvider implements DashboardDataProvider
{
    public function __construct(
        private RecommendVenuesForIntent $venues,
        private EventInterestScheduleMatcher $schedule,
    ) {}

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();

        return $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed();
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->isAvailable($user, $settings), 404);
        $ids = $user->identityIds();
        $now = now();
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $intents = PlayIntent::query()->whereIn('user_id', $ids)->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->get();
        $directIds = VenueSubscription::query()->whereIn('user_id', $ids)->where('status', 'active')
            ->pluck('venue_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($intents->isEmpty() && $directIds === []) {
            return new DashboardWidgetData(0, [], ['horizon_days' => 30]);
        }

        $exactIds = array_values(array_unique(array_merge($directIds, $intents->pluck('venue_id')->filter()->map(fn ($id): int => (int) $id)->all())));
        $broad = $intents->contains(fn (PlayIntent $intent): bool => $intent->venue_id === null || $intent->include_similar_venues);

        $query = Event::query()->where('status', EventStatusEnum::PUBLISHED->value)
            ->where('visibility', EventVisibilityEnum::PUBLIC->value)
            ->whereIn('type', [EventTypeEnum::GAME->value, EventTypeEnum::TRAINING->value, EventTypeEnum::GAME_TRAINING->value])
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addDays(30))
            ->whereNotNull('ends_at')->whereNotNull('venue_id')
            ->whereDoesntHave('organizerActor', fn (Builder $q) => $q->whereIn('user_id', $ids))
            ->when(! $broad, fn (Builder $q) => $q->whereIn('venue_id', $exactIds))
            ->with(['venue.schedule', 'venue.location.address', 'venue.amenities', 'venue.courts.amenities', 'primaryGame'])
            ->orderBy('starts_at')->orderBy('id');

        $items = [];
        $total = 0;
        foreach ($query->lazy(100) as $event) {
            $venue = $event->venue;
            if ($venue === null || ! $venue->isPubliclyVisible()
                || $venue->operational_status !== VenueOperationalStatusEnum::ACTIVE
                || $event->ends_at->lessThanOrEqualTo($now)) {
                continue;
            }
            if ($event->type === EventTypeEnum::GAME && ($event->primaryGame === null
                || ! $event->primaryGame->accepts_applications
                || $event->primaryGame->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT)) {
                continue;
            }

            $direct = in_array((int) $venue->id, $directIds, true);
            $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
            $windows = $this->schedule->candidateTimes($event, $timezone);
            $matching = $intents->filter(fn (PlayIntent $intent): bool =>
                $this->venues->matchesVenue($intent, $venue) && collect($windows)->contains(
                    fn (array $window): bool => $window[0]->greaterThan($now)
                        && $this->schedule->matchesSchedule($intent, $event, $window[0], $window[1])
                ));
            if (! $direct && $matching->isEmpty()) {
                continue;
            }

            $total++;
            if (count($items) < $limit) {
                $items[] = [
                    'id' => (int) $event->id,
                    'title' => $event->title,
                    'venue_name' => $venue->name,
                    'starts_at' => $event->starts_at->utc()->toIso8601String(),
                    'time_confirmed' => (bool) $event->time_confirmed,
                    'url' => route('events.show', $event->routeIdentifier(), false),
                    'reason' => $direct || $matching->contains(fn (PlayIntent $intent): bool => (int) $intent->venue_id === (int) $venue->id)
                        ? 'venue_interest' : 'criteria_match',
                ];
            }
        }

        return new DashboardWidgetData($total, $items, ['horizon_days' => 30]);
    }
}
