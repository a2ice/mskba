<?php

namespace App\Modules\Dashboard\Infrastructure\Listeners;

use App\Modules\Dashboard\Application\Services\DashboardInvalidator;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final readonly class InvalidateChangedEventWidgets implements ShouldQueueAfterCommit
{
    public function __construct(private DashboardInvalidator $invalidator) {}

    public function handle(EventChanged $changed): void
    {
        $event = Event::query()->withTrashed()->with(['organizerActor', 'primaryGame'])->find($changed->eventId);
        if ($event === null) {
            return;
        }

        // Schedule invalidation is sent only to identities already related to
        // the event. Actual visibility is rechecked by the read provider.
        $schedule = $event->participants()->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->pluck('user_id')->all();
        if ($event->primaryGame !== null) {
            $schedule = array_merge($schedule, $event->primaryGame->admissions()
                ->where('status', GameAdmissionStatusEnum::ACCEPTED->value)->pluck('user_id')->all());
        }
        $schedule[] = $event->organizerActor?->user_id;
        $this->invalidator->send($schedule, ['upcoming_events']);

        // Never fan out a private/draft event to subscribers. Public changes
        // may affect both positive and negative matches; send a generic signal
        // to direct subscribers and candidate intents, regardless of delivery
        // channel settings. The read provider determines the actual match.
        if ($event->visibility !== EventVisibilityEnum::PUBLIC
            || $event->status === EventStatusEnum::DRAFT || $event->venue_id === null) {
            return;
        }

        $direct = VenueSubscription::query()->where('status', 'active')
            ->where('venue_id', $event->venue_id)->select('user_id');
        $intents = PlayIntent::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('venue_id', $event->venue_id)
                ->orWhereNull('venue_id')->orWhere('include_similar_venues', true))
            ->select('user_id');

        // SQL UNION deduplicates overlapping direct subscriptions and intents.
        $this->invalidator->send($direct->toBase()->union($intents->toBase())->cursor()->pluck('user_id'), ['my_interests']);
    }
}
