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

    public function presentationQuery(Request $request, array $filters): array
    {
        return Arr::only($filters, self::PRESENTATION) + Arr::only($request->query(), self::TRACKING);
    }
}
