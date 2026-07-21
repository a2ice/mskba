<?php

namespace App\Modules\Admin\Application\Services;

use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Support\Collection;

final class VenueRevisionDiffBuilder
{
    /** @var Collection<int, MetroStation>|null */
    private ?Collection $metroStations = null;

    /**
     * @return array{
     *     fields: array<int, array{label: string, before: string, after: string}>,
     *     gallery_changed: bool,
     *     gallery_summary: string,
     *     before_gallery: array<int, array{url: string, is_featured: bool, state: string|null}>,
     *     after_gallery: array<int, array{url: string, is_featured: bool, state: string|null}>,
     *     has_changes: bool
     * }
     */
    public function build(Venue $venue, VenueRevision $revision): array
    {
        $venue->loadMissing('location.address', 'location.metroStations.line', 'tags', 'media');
        $revision->loadMissing('media');

        $payload = $revision->payload;
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $location = is_array($payload['location'] ?? null) ? $payload['location'] : [];
        $fields = [];

        $this->addChange($fields, 'Название', $venue->name, $details['name'] ?? null);
        $this->addChange(
            $fields,
            'Тип',
            $venue->type->label(),
            VenueTypeEnum::tryFrom((string) ($details['type'] ?? ''))?->label(),
        );
        $this->addChange(
            $fields,
            'Адрес',
            $venue->location?->address?->full_address ?? $venue->raw_address,
            $location['raw_address'] ?? null,
        );
        $this->addChange(
            $fields,
            'Координаты',
            $this->coordinates($venue->location?->address?->latitude, $venue->location?->address?->longitude),
            $this->coordinates($location['latitude'] ?? null, $location['longitude'] ?? null),
        );
        $this->addChange($fields, 'Краткое описание', $venue->short_description, $details['short_description'] ?? null);
        $this->addChange($fields, 'Полное описание', $venue->full_description, $details['full_description'] ?? null);

        $currentTags = $venue->tags->pluck('name')->filter()->values()->all();
        $proposedTags = array_values(array_filter($payload['tags'] ?? [], 'is_string'));
        if ($this->sorted($currentTags) !== $this->sorted($proposedTags)) {
            $this->addChange($fields, 'Теги', implode(', ', $currentTags), implode(', ', $proposedTags), force: true);
        }

        $currentMetroIds = $venue->location?->metroStations?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
        $proposedMetroIds = array_map('intval', is_array($location['metro_station_ids'] ?? null) ? $location['metro_station_ids'] : []);
        if ($this->sorted($currentMetroIds) !== $this->sorted($proposedMetroIds)) {
            $this->addChange(
                $fields,
                'Ближайшее метро',
                $this->metroLabels($currentMetroIds),
                $this->metroLabels($proposedMetroIds),
                force: true,
            );
        }

        $gallery = $this->galleryDiff($venue, $revision, $payload);

        return [
            'fields' => $fields,
            ...$gallery,
            'has_changes' => $fields !== [] || $gallery['gallery_changed'],
        ];
    }

    /** @param array<int, array{label: string, before: string, after: string}> $fields */
    private function addChange(array &$fields, string $label, mixed $before, mixed $after, bool $force = false): void
    {
        $before = $this->display($before);
        $after = $this->display($after);

        if (! $force && $before === $after) {
            return;
        }

        $fields[] = compact('label', 'before', 'after');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     gallery_changed: bool,
     *     gallery_summary: string,
     *     before_gallery: array<int, array{url: string, is_featured: bool, state: string|null}>,
     *     after_gallery: array<int, array{url: string, is_featured: bool, state: string|null}>
     * }
     */
    private function galleryDiff(Venue $venue, VenueRevision $revision, array $payload): array
    {
        $published = $venue->media->where('collection', 'gallery')
            ->sortBy([['is_featured', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();
        $publishedById = $published->keyBy('id');
        $draftById = $revision->media->where('collection', 'gallery')->keyBy('id');
        $proposedItems = collect(is_array($payload['gallery'] ?? null) ? $payload['gallery'] : [])
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['id']))
            ->values();
        $keptPublishedIds = $proposedItems
            ->filter(fn (array $item): bool => ($item['kind'] ?? null) !== 'draft')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $removed = $published->pluck('id')->map(fn ($id): int => (int) $id)->diff($keptPublishedIds)->all();
        $addedCount = $proposedItems->where('kind', 'draft')->count();
        $currentSignature = $published->values()->map(fn (Media $media, int $index): string => "published:{$media->id}:".($index === 0 ? '1' : '0'))->all();
        $proposedSignature = $proposedItems->map(fn (array $item, int $index): string => ($item['kind'] ?? 'published').':'.(int) $item['id'].':'.($index === 0 ? '1' : '0'))->all();
        $galleryChanged = $currentSignature !== $proposedSignature;
        $featuredChanged = ($currentSignature[0] ?? null) !== ($proposedSignature[0] ?? null);
        $summary = collect([
            $addedCount > 0 ? "добавлено: {$addedCount}" : null,
            count($removed) > 0 ? 'удалено: '.count($removed) : null,
            $featuredChanged ? 'изменена основная фотография' : null,
        ])->filter()->implode(' · ');

        return [
            'gallery_changed' => $galleryChanged,
            'gallery_summary' => $summary !== '' ? $summary : 'состав фотографий изменён',
            'before_gallery' => $published->map(fn (Media $media, int $index): array => [
                'url' => $media->publicUrl(),
                'is_featured' => $index === 0,
                'state' => in_array($media->id, $removed, true) ? 'removed' : null,
            ])->all(),
            'after_gallery' => $proposedItems->map(function (array $item, int $index) use ($publishedById, $draftById): ?array {
                $isDraft = ($item['kind'] ?? null) === 'draft';
                $media = ($isDraft ? $draftById : $publishedById)->get((int) $item['id']);

                return $media instanceof Media ? [
                    'url' => $media->publicUrl(),
                    'is_featured' => $index === 0,
                    'state' => $isDraft ? 'added' : null,
                ] : null;
            })->filter()->values()->all(),
        ];
    }

    /** @param array<int, int> $ids */
    private function metroLabels(array $ids): string
    {
        $this->metroStations ??= MetroStation::query()->with('line')->get()->keyBy('id');

        return collect($ids)->map(function (int $id): string {
            $station = $this->metroStations?->get($id);

            return $station instanceof MetroStation
                ? $station->name.($station->line?->name ? ' ('.$station->line->name.')' : '')
                : "Станция #{$id}";
        })->implode(', ');
    }

    private function coordinates(mixed $latitude, mixed $longitude): string
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return '';
        }

        return number_format((float) $latitude, 6, '.', '').', '.number_format((float) $longitude, 6, '.', '');
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : '—';
    }

    /** @param array<int, int|string> $values @return array<int, int|string> */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}
