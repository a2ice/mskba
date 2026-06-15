<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueReviewDTO
{
    public function __construct(
        public int $id,
        public int $rating,
        public ?string $body,
        public string $authorName,
        public ?string $publishedAt,
    ) {}
}
