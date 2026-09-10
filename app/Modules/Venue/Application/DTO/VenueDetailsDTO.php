<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueDetailsDTO
{
    /**
     * @param  array<int, array{id: string, label: string, isAvailable: bool}>  $sections
     * @param  array<int, array{id: int, name: string, alias: string, routeIdentifier: string, isPrimary: bool, supportsHalves: bool, hoopsCount: ?int, surfaceType: ?string, surfaceLabel: ?string, allowsWhole: bool, allowsHalves: bool}>  $courts
     * @param  array{id: int, name: string, alias: string, routeIdentifier: string, isPrimary: bool, supportsHalves: bool, hoopsCount: ?int, surfaceType: ?string, surfaceLabel: ?string, allowsWhole: bool, allowsHalves: bool}  $selectedCourt
     * @param  array<int, array{id: int, name: string, description: ?string, iconUrl: ?string}>  $amenities
     * @param  array<int, array{id: int, title: ?string, url: ?string}>  $featuredMedia
     * @param  array<int, VenueReviewDTO>  $reviews
     * @param  array<int, array{date: string, label: string, weekday: string, isToday: bool, state: string, slots: array<int, array{timeLabel: string, eventTypeLabel: string, eventUrl: ?string, statusIcon: string, statusLabel: string, status: string}>}>  $occupancyDays
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $type,
        public string $typeSlug,
        public string $status,
        public string $statusSlug,
        public bool $isOpen,
        public string $todayHours,
        public ?string $shortDescription,
        public ?string $fullDescription,
        public ?string $rawAddress,
        public ?VenueAddressDTO $address,
        public array $metroStations,
        public VenueAboutDTO $about,
        public array $sections,
        public array $courts,
        public array $selectedCourt,
        public array $amenities,
        public array $featuredMedia,
        public array $reviews,
        public array $occupancyDays,
        public bool $canEdit,
        public bool $canEditSchedule,
        public bool $canRemove,
    ) {}

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }
}
