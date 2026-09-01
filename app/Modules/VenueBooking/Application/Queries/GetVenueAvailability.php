<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class GetVenueAvailability
{
    /** @return array<string, mixed> */
    public function handle(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lessThanOrEqualTo($from) || $from->diffInDays($to) > 31) {
            throw new InvalidArgumentException('Диапазон доступности должен быть положительным и не превышать 31 день.');
        }

        $busy = VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->limit(500)
            ->get(['public_id', 'scope', 'starts_at', 'ends_at', 'optimistic_version', 'updated_at']);

        return [
            'venue_id' => $venue->id,
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
