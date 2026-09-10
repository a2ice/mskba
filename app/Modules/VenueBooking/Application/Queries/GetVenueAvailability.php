<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class GetVenueAvailability
{
    /** @return array<string, mixed> */
    public function handle(
        Venue $venue,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?VenueCourt $court = null,
    ): array {
        if ($to->lessThanOrEqualTo($from) || $from->diffInDays($to) > 31) {
            throw new InvalidArgumentException('Диапазон доступности должен быть положительным и не превышать 31 день.');
        }

        $court ??= $venue->primaryCourt()->first() ?? $venue->courts()->first();
        if ($court === null || $court->venue_id !== $venue->id || $court->trashed()) {
            throw new InvalidArgumentException('Выбранный зал недоступен.');
        }

        $busy = VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->where(function ($query) use ($court): void {
                // A nullable court is a legacy/ambiguous reservation and stays conservative.
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            })
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->limit(500)
            ->get(['public_id', 'venue_court_id', 'scope', 'starts_at', 'ends_at', 'optimistic_version', 'updated_at']);

        return [
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'venue_court_name' => $court->name,
            'from' => $from->utc()->toIso8601String(),
            'to' => $to->utc()->toIso8601String(),
            'projection_version' => $busy->max('updated_at')?->utc()->toIso8601String(),
            'busy' => $busy->map(fn (VenueBooking $booking): array => [
                'scope' => $booking->scope?->value,
                'starts_at' => $booking->starts_at->utc()->toIso8601String(),
                'ends_at' => $booking->ends_at->utc()->toIso8601String(),
                'version' => $booking->optimistic_version,
            ])->values()->all(),
        ];
    }
}
