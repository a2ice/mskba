<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Internal integration gate: never publish or index a landing by itself. */
final readonly class VenueCatalogLandingNavigation
{
    public const SLUG_PATTERN = '(?!(?:courts|preview|nearby-map)(?:/|$))[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,3}';

    private const PRESENTATION = ['sort', 'view', 'page', 'fragment'];
    private const TRACKING = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'yclid', 'gclid'];

    public function __construct(private VenueCatalogLandingResolver $resolver) {}

    public function enabled(): bool
    {
        return (bool) config('features.venue_catalog_landings.enabled', false);
    }

    public function prepare(Request $request, ?string $slug): ?VenueCatalogLanding
    {
        if ($slug === null) {
            return null;
        }

        abort_unless($this->enabled(), 404);
        $landing = $this->resolver->forSlug($slug);
        abort_if($landing === null, 404);

        // Explicit query parameters override the defaults. Validation is still
        // performed by the existing catalogue controller on the effective query.
        $request->query->replace(array_replace($landing->criteria(), $request->query()));

        return $landing;
    }

    public function preparePreview(Request $request): VenueCatalogLanding
    {
        \Illuminate\Support\Facades\Gate::authorize('manage-content');
        $landing = VenueCatalogLanding::query()->findOrFail((int) $request->route('landingId'));

        // Preview always uses saved criteria; query overrides cannot change them.
        $request->query->replace($landing->criteria() + Arr::only($request->query(), self::PRESENTATION));

        return $landing;
    }

    public function previewUrl(VenueCatalogLanding $landing, array $query = []): string
    {
        return route('admin.content.catalog-landings.preview', ['landingId' => $landing->id] + Arr::only($query, self::PRESENTATION));
    }

    public function match(Request $request, array $validated): ?VenueCatalogLanding
    {
        if (! $this->enabled()) {
            return null;
        }

        // Unknown query conditions must not silently acquire editorial content.
        if (array_diff(array_keys($request->query()), array_keys($validated), self::TRACKING) !== []) {
            return null;
        }

        $landing = $this->resolver->forCatalog($validated);

        return $landing !== null && self::validSlug($landing->slug) ? $landing : null;
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match('~\\A'.self::SLUG_PATTERN.'\\z~D', $slug) === 1;
    }

    public function url(VenueCatalogLanding $landing, array $query = []): string
    {
        return route('venues.collections.show', ['landingSlug' => $landing->slug] + Arr::only($query, [...self::PRESENTATION, ...self::TRACKING]));
    }

    /** @return list<array{url: string, label: string}> */
    public function relatedLinks(VenueCatalogLanding $landing): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', $landing->related_landing_ids ?? []),
            fn (int $id): bool => $id > 0 && $id !== (int) $landing->id,
        ))), 0, 12);
        if ($ids === []) {
            return [];
        }
        $records = VenueCatalogLanding::query()->whereIn('id', $ids)
            ->where('is_active', true)->get(['id', 'slug', 'h1'])->keyBy('id');

        $links = [];
        foreach ($ids as $id) {
            $record = $records->get($id);
            if ($record !== null && self::validSlug($record->slug)) {
                $links[] = ['url' => $this->url($record), 'label' => $record->h1];
            }
        }

        return $links;
    }

    public function presentationQuery(Request $request, array $filters): array
    {
        return Arr::only($filters, self::PRESENTATION) + Arr::only($request->query(), self::TRACKING);
    }
}
