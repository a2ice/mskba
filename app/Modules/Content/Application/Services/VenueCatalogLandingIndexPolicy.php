<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use Illuminate\Http\Request;

final readonly class VenueCatalogLandingIndexPolicy
{
    public const BLOCKED = 'noindex, nofollow, noarchive';

    public function __construct(private VenueCatalogLandingIndexReadiness $readiness) {}

    public function enabled(Request $request): bool
    {
        $host = strtolower($request->getHost());
        $canonicalHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return config('features.venue_catalog_landings.enabled', false)
            && config('features.venue_catalog_landings.indexing', false)
            && $canonicalHost === $host
            && in_array($host, array_map('strtolower', (array) config('seo.indexable_hosts', [])), true)
            && $request->isSecure();
    }

    /** The same editorial eligibility is used by HTML and sitemap. */
    public function eligible(VenueCatalogLanding $landing, int $matchingVenues): bool
    {
        return $landing->is_active
            && $landing->allow_indexing
            && $matchingVenues > 0
            && VenueCatalogLandingNavigation::validSlug($landing->slug)
            && ! in_array(false, $this->readiness->checks($landing, $matchingVenues), true);
    }

    public function robots(Request $request, VenueCatalogLanding $landing, int $matchingVenues, bool $publicPage, bool $cleanPublicUrl): string
    {
        if (! $this->enabled($request) || ! $publicPage || ! $this->eligible($landing, $matchingVenues)) {
            return self::BLOCKED;
        }

        return $cleanPublicUrl ? 'index, follow' : 'noindex, follow';
    }
}
