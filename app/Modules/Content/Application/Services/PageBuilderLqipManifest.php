<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Media\Domain\Models\SiteMediaAsset;

/**
 * Read-only, bounded map of optional previews referenced by sanitized Page
 * Builder HTML/CSS. Never changes editor snapshots or emits CSS/HTML code.
 */
final class PageBuilderLqipManifest
{
    /** @return array<string, string> uuid => same-origin thumbnail URL */
    public function forContent(string $html, string $css): array
    {
        preg_match_all(
            '~(?:/media/site/)([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-f-])~i',
            $html."\n".$css,
            $matches,
        );
        $uuids = array_slice(array_values(array_unique(array_map('strtolower', $matches[1] ?? []))), 0, 150);
        if ($uuids === []) {
            return [];
        }

        $result = [];
        foreach (SiteMediaAsset::query()->whereIn('uuid', $uuids)->with('currentMedia')->get() as $asset) {
            $url = $asset->lqipUrl();
            if ($url !== null) {
                $result[strtolower($asset->uuid)] = $url;
            }
        }

        return $result;
    }
}
