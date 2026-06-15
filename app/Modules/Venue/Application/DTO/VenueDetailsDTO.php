<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueDetailsDTO
{
    /**
     * @param  array<int, array{id: string, label: string, isAvailable: bool}>  $sections
     * @param  array<int, array{id: int, name: string, description: ?string, iconUrl: ?string}>  $amenities
     * @param  array<int, array{id: int, title: ?string, url: ?string}>  $featuredMedia
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $type,
        public string $typeSlug,
        public string $status,
        public ?string $description,
        public ?string $rawAddress,
        public ?VenueAddressDTO $address,
        public array $metroStations,
        public VenueAboutDTO $about,
        public array $sections,
        public array $amenities,
        public array $featuredMedia,
        public bool $canEdit,
        public bool $canEditSchedule,
        public bool $canRemove,
    ) {}
}
