<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Application\DTO\VenueAboutDTO;
use App\Modules\Venue\Application\DTO\VenueAddressDTO;
use App\Modules\Venue\Application\DTO\VenueAmenityDTO;
use App\Modules\Venue\Application\DTO\VenueDetailsDTO;
use App\Modules\Venue\Application\DTO\VenueMetroStationDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;

final class ShowVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(string $alias, ?User $user): VenueDetailsDTO
    {
        $venue = Venue::query()
            ->with([
                'location.address',
                'location.metroStations.line',
                'media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'amenities' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->where('alias', $alias)
            ->first();

        if ($venue === null) {
            throw new VenueNotFoundException;
        }
        if (! $this->access->canView($user, $venue)) {
            throw new VenueAccessDeniedException;
        }

        $address = $venue->location?->address;
        $displayAddress = $this->displayAddress($address, $venue->raw_address);
        $metroStations = ($venue->location?->metroStations ?? collect())
            ->map(fn (MetroStation $station) => new VenueMetroStationDTO(
                id: (int) $station->id,
                name: $station->name,
                lineName: $station->line?->name,
                lineColor: $station->line?->color,
                latitude: $station->latitude === null ? null : (string) $station->latitude,
                longitude: $station->longitude === null ? null : (string) $station->longitude,
                distanceMeters: $station->pivot?->distance_meters === null ? null : (int) $station->pivot->distance_meters,
                walkingTimeMinutes: $station->pivot?->walking_time_minutes === null ? null : (int) $station->pivot->walking_time_minutes,
            ))
            ->values()
            ->all();
        $featuredMedia = $venue->media
            ->take(8)
            ->map(fn (Media $media) => [
                'id' => (int) $media->id,
                'title' => $media->title,
                'description' => $media->description,
                'url' => $media->publicUrl(),
                'isFeatured' => (bool) $media->is_featured,
            ])
            ->values()
            ->all();
        $amenities = $venue->amenities
            ->map(fn ($amenity) => new VenueAmenityDTO(
                id: (int) $amenity->id,
                name: $amenity->name,
                alias: $amenity->alias,
                description: $amenity->description,
                icon: $amenity->icon,
                note: $amenity->pivot?->note,
            ))
            ->values()
            ->all();
        $sections = [
            ['id' => 'address', 'label' => 'Адрес', 'isAvailable' => $displayAddress !== ''],
            ['id' => 'amenities', 'label' => 'Опции', 'isAvailable' => $amenities !== []],
            ['id' => 'schedule', 'label' => 'Расписание', 'isAvailable' => false],
            ['id' => 'posts', 'label' => 'Посты', 'isAvailable' => false],
            ['id' => 'reviews', 'label' => 'Отзывы', 'isAvailable' => false],
        ];

        if ($featuredMedia !== []) {
            array_unshift($sections, ['id' => 'gallery', 'label' => 'Галерея', 'isAvailable' => true]);
        }

        return new VenueDetailsDTO(
            id: $venue->id,
            name: $venue->name,
            alias: $venue->alias,
            type: $venue->type->label(),
            typeSlug: $venue->type->publicSlug(),
            status: $venue->status->label(),
            description: $venue->description,
            rawAddress: $venue->raw_address,
            address: $address === null ? null : new VenueAddressDTO(
                city: $address->city,
                street: $address->street,
                building: $address->building,
                postalCode: $address->postal_code,
                latitude: $address->latitude === null ? null : (string) $address->latitude,
                longitude: $address->longitude === null ? null : (string) $address->longitude,
                fullAddress: $address->full_address,
                display: $displayAddress,
            ),
            metroStations: $metroStations,
            about: new VenueAboutDTO(
                rating: null,
                ratingCount: null,
                scheduleDays: [],
                scheduleUrl: null,
                feedUrl: null,
                bookingUrl: null,
                mapApiKey: config('integrations.yandex.api_key'),
            ),
            sections: $sections,
            amenities: $amenities,
            featuredMedia: $featuredMedia,
            canEdit: $this->access->canEdit($user, $venue),
            canEditSchedule: $this->access->canEditSchedule($user, $venue),
            canRemove: $this->access->canRemove($user, $venue),
        );
    }

    private function displayAddress(?Address $address, ?string $rawAddress): string
    {
        if ($address?->full_address) {
            return $address->full_address;
        }

        $parts = array_filter([
            $address?->city,
            $address?->street,
            $address?->building,
        ]);

        return $parts === []
            ? (string) ($rawAddress ?? '')
            : implode(', ', $parts);
    }
}
