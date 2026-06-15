<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueAboutDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $scheduleDays
     */
    public function __construct(
        public ?float $rating,
        public ?int $ratingCount,
        public array $scheduleDays,
        public ?string $scheduleUrl,
        public ?string $feedUrl,
        public ?string $bookingUrl,
        public ?string $mapApiKey,
    ) {}
}
