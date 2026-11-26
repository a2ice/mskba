<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use Illuminate\Support\Facades\Gate;

final readonly class VenueCatalogLandingIndexReadiness
{
    public function __construct(private ContentBodySanitizer $sanitizer) {}

    /**
     * Editorial dry-run only. Never grants indexing, changes flags, or writes
     * sitemap entries. A human must still review relevance and factual quality.
     *
     * @return array{checks:array<string,bool>, sitemap_requested:bool}
     */
    public function inspect(VenueCatalogLanding $landing, int $matchingVenues): array
    {
        Gate::authorize('manage-content');

        return [
            'checks' => $this->checks($landing, $matchingVenues),
            'sitemap_requested' => $landing->include_in_sitemap,
        ];
    }

    /** Internal, read-only eligibility facts; not an authorization boundary. */
    public function checks(VenueCatalogLanding $landing, int $matchingVenues): array
    {
        $seo = PageSeoSetting::query()
            ->where('entity_type', SeoEntityTypeEnum::VENUE_CATALOG_LANDING->value)
            ->where('entity_id', $landing->getKey())
            ->first();

        return [
            'active' => $landing->is_active,
            'nonempty' => $matchingVenues > 0,
            'intro' => $this->hasText($landing->intro_text),
            'body' => $this->hasText($landing->body_text),
            'meta_title' => $this->hasText($seo?->meta_title),
            'meta_description' => $this->hasText($seo?->meta_description),
            'index_requested' => $landing->allow_indexing,
        ];
    }

    private function hasText(?string $value): bool
    {
        $text = html_entity_decode(strip_tags($this->sanitizer->sanitize((string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\p{Z}\p{Cf}]+/u', ' ', $text)) !== '';
    }
}
