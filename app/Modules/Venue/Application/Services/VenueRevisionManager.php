<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCharacteristic;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class VenueRevisionManager
{
    public function __construct(
        private readonly VenueDetailsUpdater $detailsUpdater,
    ) {}

    public function draftFor(Venue $venue): ?VenueRevision
    {
        return $venue->revisions()
            ->whereNull('applied_at')
            ->with('media')
            ->latest('id')
            ->first();
    }

    public function getOrCreateDraft(Venue $venue, ?Actor $actor): VenueRevision
    {
        $draft = $venue->revisions()
            ->whereNull('applied_at')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        $venue->loadMissing('location.address', 'location.metroStations', 'tags', 'media', 'amenities');

        return $venue->revisions()->create([
            'created_by_actor_id' => $actor?->id,
            'payload' => $this->snapshot($venue),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames
     */
    public function saveDetails(
        Venue $venue,
        ?Actor $actor,
        array $data,
        CreateLocationDTO $location,
        array $tagNames,
    ): VenueRevision {
        $revision = $this->getOrCreateDraft($venue, $actor);
        $payload = $revision->payload;

        if ((int) ($payload['base_content_version'] ?? -1) !== $venue->content_version) {
            $draftItems = collect($payload['gallery'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && ($item['kind'] ?? null) === 'draft')
                ->values()
                ->all();
            $payload = $this->snapshot($venue);
            $payload['gallery'] = collect($draftItems)
                ->concat($payload['gallery'])
                ->take(3)
                ->values()
                ->map(fn (array $item, int $index): array => array_replace($item, [
                    'is_featured' => $index === 0,
                    'sort_order' => $index,
                ]))
                ->all();
        }

        $payload['details'] = [
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'access_type' => (string) ($data['access_type'] ?? $payload['details']['access_type'] ?? 'unknown'),
            'requires_booking_approval' => array_key_exists('requires_booking_approval', $data)
                ? (bool) $data['requires_booking_approval']
                : (bool) ($payload['details']['requires_booking_approval'] ?? false),
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
        ];
        if ($location->hasData()) {
            $payload['location'] = [
                'raw_address' => $location->rawAddress,
                'city' => $location->city,
                'street' => $location->street,
                'building' => $location->building,
                'postal_code' => $location->postalCode,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'metro_station_ids' => $location->metroStationIds,
            ];
        }
        $payload['tags'] = array_values($tagNames);

        if ((bool) ($data['facilities_present'] ?? false)) {
            $payload['facilities'] = [
                'characteristics' => is_array($data['characteristics'] ?? null) ? $data['characteristics'] : [],
                'amenity_ids' => collect(is_array($data['amenity_ids'] ?? null) ? $data['amenity_ids'] : [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        $revision->forceFill(['payload' => $payload])->save();

        return $revision->refresh();
    }

    public function apply(VenueRevision $revision): Venue
    {
        $revision->loadMissing('venue', 'media');

        if ($revision->applied_at !== null) {
            throw new InvalidArgumentException('Эта ревизия уже применена.');
        }

        $payload = $revision->payload;
        $details = $payload['details'] ?? [];
        $location = $payload['location'] ?? [];
        $facilities = is_array($payload['facilities'] ?? null) ? $payload['facilities'] : [];

        $this->assertCurrent($revision);

        if (! is_array($details) || ! is_array($location)) {
            throw new InvalidArgumentException('Данные ревизии повреждены.');
        }

        $venue = $this->detailsUpdater->update(
            $revision->venue,
            array_replace($details, [
                'facilities_present' => true,
                'characteristics' => is_array($facilities['characteristics'] ?? null)
                    ? $facilities['characteristics']
                    : [],
                'amenity_ids' => is_array($facilities['amenity_ids'] ?? null)
                    ? $facilities['amenity_ids']
                    : [],
            ]),
            new CreateLocationDTO(
                rawAddress: $this->nullableString($location['raw_address'] ?? null),
                city: $this->nullableString($location['city'] ?? null),
                street: $this->nullableString($location['street'] ?? null),
                building: $this->nullableString($location['building'] ?? null),
                postalCode: $this->nullableString($location['postal_code'] ?? null),
                latitude: is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
                longitude: is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
                metroStationIds: array_map('intval', is_array($location['metro_station_ids'] ?? null) ? $location['metro_station_ids'] : []),
            ),
            array_values(array_filter($payload['tags'] ?? [], 'is_string')),
        );

        $this->applyGallery($venue, $revision, is_array($payload['gallery'] ?? null) ? $payload['gallery'] : []);
        $revision->forceFill(['applied_at' => now()])->save();

        return $venue->refresh();
    }

    public function assertCurrent(VenueRevision $revision): void
    {
        $revision->loadMissing('venue');

        if ((int) ($revision->payload['base_content_version'] ?? -1) !== $revision->venue->content_version) {
            throw new InvalidArgumentException('Опубликованная площадка изменилась после создания ревизии. Сохраните изменения заново.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Venue $venue): array
    {
        $address = $venue->location?->address;
        $characteristics = VenueCharacteristic::query()->where('venue_id', $venue->id)->first();

        return [
            'base_content_version' => $venue->content_version,
            'details' => [
                'name' => $venue->name,
                'type' => $venue->type->value,
                'access_type' => $venue->requires_payment === null ? 'unknown' : ($venue->requires_payment ? 'paid' : 'free'),
                'requires_booking_approval' => $venue->requires_booking_approval,
                'short_description' => $venue->short_description,
                'full_description' => $venue->full_description,
            ],
            'location' => [
                'raw_address' => $address?->full_address ?? $venue->raw_address,
                'city' => $address?->city,
                'street' => $address?->street,
                'building' => $address?->building,
                'postal_code' => $address?->postal_code,
                'latitude' => $address?->latitude,
                'longitude' => $address?->longitude,
                'metro_station_ids' => $venue->location?->metroStations?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [],
            ],
            'tags' => $venue->tags->pluck('name')->values()->all(),
            'facilities' => [
                'characteristics' => $characteristics === null ? [] : [
                    'hoops_count' => $characteristics->hoops_count,
                    'hoops_condition' => $characteristics->hoops_condition,
                    'surface_condition' => $characteristics->surface_condition,
                    'first_hoop_marking' => $characteristics->first_hoop_marking?->value,
                    'second_hoop_marking' => $characteristics->second_hoop_marking?->value,
                ],
                'amenity_ids' => $venue->amenities->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ],
            'gallery' => $venue->media
                ->where('collection', 'gallery')
                ->sortBy([
                    ['is_featured', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->take(3)
                ->values()
                ->map(fn (Media $media, int $index): array => [
                    'kind' => 'published',
                    'id' => (int) $media->id,
                    'is_featured' => $index === 0,
                    'sort_order' => $index,
                ])
                ->all(),
        ];
    }

    /** @param array<int, mixed> $gallery */
    private function applyGallery(Venue $venue, VenueRevision $revision, array $gallery): void
    {
        $published = $venue->media()->where('collection', 'gallery')->lockForUpdate()->get();
        $draftMedia = $revision->media()->where('collection', 'gallery')->lockForUpdate()->get();
        $keptPublishedIds = [];
        $usedDraftIds = [];

        foreach (array_values($gallery) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $media = ($item['kind'] ?? null) === 'draft'
                ? $draftMedia->firstWhere('id', (int) ($item['id'] ?? 0))
                : $published->firstWhere('id', (int) ($item['id'] ?? 0));

            if (! $media instanceof Media) {
                throw new InvalidArgumentException('Одна из фотографий ревизии недоступна.');
            }

            if (($item['kind'] ?? null) === 'draft') {
                $usedDraftIds[] = $media->id;
                $media->forceFill([
                    'mediable_type' => $venue->getMorphClass(),
                    'mediable_id' => $venue->id,
                ]);
            } else {
                $keptPublishedIds[] = $media->id;
            }

            $media->forceFill([
                'is_featured' => $index === 0,
                'sort_order' => $index,
            ])->save();
        }

        foreach ($published->whereNotIn('id', $keptPublishedIds) as $media) {
            $this->deleteMediaAfterCommit($media);
        }

        foreach ($draftMedia->whereNotIn('id', $usedDraftIds) as $media) {
            $this->deleteMediaAfterCommit($media);
        }
    }

    private function deleteMediaAfterCommit(Media $media): void
    {
        $disk = $media->disk;
        $path = $media->path;
        $media->delete();
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
