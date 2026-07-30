<?php

namespace App\Modules\Coordination\Application\Services;

use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CoordinationFlowAdvancer
{
    public function __construct(private readonly VenueEventAvailability $availability) {}

    public function activateNext(CoordinationSession $session, Poll $completedPoll): ?Poll
    {
        /** @var Poll|null $next */
        $next = $session->polls()
            ->where('step_order', '>', $completedPoll->step_order)
            ->where('status', PollStatusEnum::DRAFT->value)
            ->first();

        if ($next === null) {
            return null;
        }

        if ($next->subject_type === PollSubjectTypeEnum::VENUE) {
            $this->refreshAvailableVenues($session, $next);
        }

        $next->forceFill([
            'status' => PollStatusEnum::OPEN,
            'closes_at' => now()->addMinutes($next->voting_duration_minutes),
            'closed_at' => null,
            'closed_by_actor_id' => null,
        ])->save();

        return $next->refresh();
    }

    private function refreshAvailableVenues(CoordinationSession $session, Poll $venuePoll): void
    {
        $decisions = $session->decisions()
            ->with(['poll', 'option'])
            ->get()
            ->keyBy(fn ($decision): string => $decision->poll->subject_type->value);
        $date = $decisions->get(PollSubjectTypeEnum::DATE->value)?->option?->value['date'] ?? null;
        $interval = $decisions->get(PollSubjectTypeEnum::TIME_INTERVAL->value)?->option?->value ?? null;

        if (! is_string($date) || ! is_array($interval)) {
            throw new InvalidArgumentException('Сначала согласуйте дату и время.');
        }

        $availableVenueIds = [];
        $excludedBookingId = $venuePoll->configuration['excluded_booking_id'] ?? null;

        foreach ($venuePoll->options()->get() as $option) {
            $venueId = (int) ($option->value['venue_id'] ?? 0);
            $venue = Venue::query()
                ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
                ->find($venueId);

            if ($venue === null) {
                continue;
            }

            $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
            // PostgreSQL connection uses the application timezone, therefore
            // bindings must remain in venue-local time instead of being shifted
            // to UTC and interpreted as local time for a second time.
            $startsAt = CarbonImmutable::parse($date.' '.($interval['starts_at'] ?? ''), $timezone);
            $endsAt = CarbonImmutable::parse($date.' '.($interval['ends_at'] ?? ''), $timezone);

            try {
                $this->availability->assertAvailable(
                    $venue,
                    $startsAt,
                    $endsAt,
                    is_numeric($excludedBookingId) ? (int) $excludedBookingId : null,
                );
                $availableVenueIds[] = $venueId;
            } catch (InvalidArgumentException) {
                // Вариант остаётся в истории цепочки, но не предлагается на этом этапе.
            }
        }

        $venuePoll->options()->update(['is_active' => false]);
        $venuePoll->options()->whereIn('value->venue_id', $availableVenueIds)->update(['is_active' => true]);

        if ($availableVenueIds === []) {
            throw new InvalidArgumentException(
                'На выбранные дату и время ни одна из площадок не свободна. Примите другой вариант времени.',
            );
        }
    }
}
