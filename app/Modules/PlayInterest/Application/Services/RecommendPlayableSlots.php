<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RecommendPlayableSlots
{
    public function __construct(
        private RecommendVenuesForIntent $venues,
        private VenueEventAvailability $availability,
    ) {}

    /**
     * Calculate real slots only for a concrete local date/time and duration.
     * The source of truth is VenueEventAvailability (court-aware booking,
     * schedule and operational conflicts); the method never reserves anything.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(
        PlayIntent $intent,
        string $date,
        string $time,
        int $durationMinutes,
        int $toleranceMinutes = 120,
    ): array {
        $candidates = $this->venues->handle($intent, 50);
        $byVenue = collect($candidates)->keyBy('venue_id');
        if ($byVenue->isEmpty()) {
            return [];
        }

        $venues = Venue::query()
            ->with(['courts', 'schedule.intervals', 'schedule.exceptions.intervals'])
            ->whereKey($byVenue->keys()->all())
            ->get();

        $shifts = [0];
        for ($m = 30; $m <= $toleranceMinutes; $m += 30) {
            $shifts[] = -$m;
            $shifts[] = $m;
        }

        $matches = [];
        foreach ($venues as $venue) {
            /** @var Venue $venue */
            $candidate = $byVenue->get($venue->id);
            $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');

            // Without published operating intervals, a successful booking
            // conflict check is insufficient to claim the venue is free.
            if ($venue->schedule === null || $venue->schedule->intervals->isEmpty()) {
                continue;
            }

            $wantedAt = CarbonImmutable::parse("{$date} {$time}", $timezone);
            if ($wantedAt->lessThan(CarbonImmutable::now($timezone))) {
                continue;
            }

            // Consider a venue's actual rooms independently; a booking of one
            // room must not remove a second available room from recommendations.
            foreach ($venue->courts->whereNull('deleted_at') as $court) {
                foreach ($shifts as $shift) {
                    $from = $wantedAt->addMinutes($shift);
                    $to = $from->addMinutes($durationMinutes);
                    if ($from->isPast()) {
                        continue;
                    }
                    try {
                        $this->availability->assertAvailable($venue, $from, $to, court: $court);
                    } catch (InvalidArgumentException) {
                        continue;
                    }

                    $distance = $candidate['distance_meters'];
                    $radiusMeters = max(1000, (int) ($intent->radius_km ?? 10) * 1000);
                    $geo = $distance === null ? 100.0 : max(0, 100.0 * (1 - $distance / $radiusMeters));
                    $soft = $candidate['preferred_amenities_total'] === 0 ? 100.0
                        : 100.0 * $candidate['preferred_amenities_matched'] / $candidate['preferred_amenities_total'];
                    $timeScore = 100.0 * (1 - abs($shift) / max(30, $toleranceMinutes + 30));
                    $score = (int) round(.45 * $timeScore + .35 * $geo + .20 * $soft);
                    $matches[] = [
                        'venue_id' => $venue->id,
                        'venue_name' => $venue->name,
                        'venue_url' => $candidate['url'],
                        'venue_court_id' => $court->id,
                        'venue_court_name' => $court->name,
                        'starts_at' => $from->toIso8601String(),
                        'ends_at' => $to->toIso8601String(),
                        'shift_minutes' => $shift,
                        'distance_meters' => $distance,
                        'score' => $score,
                        'booking_availability' => 'available_at_check',
                        'verified_at' => now()->toIso8601String(),
                        'booking_guaranteed' => false,
                    ];
                    // Closest fitting time per court; other courts/venues remain.
                    break;
                }
            }
        }

        usort($matches, fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: abs($a['shift_minutes']) <=> abs($b['shift_minutes'])
            ?: $a['venue_id'] <=> $b['venue_id']);

        return array_slice($matches, 0, 30);
    }
}
