<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Support\Str;

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
        $uuids = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));

        // Tournaments art is assigned later by the published CMS JavaScript
        // from its public scene API, not written into CSS/HTML at render time.
        // Resolve the very same three newest named folder assets used by that
        // API; don't fetch all images from the library.
        if (str_contains($html, 'mskba-tournaments')) {
            $folder = SiteMediaFolder::query()->where('slug', 'home-tournaments-section')->first();
            if ($folder !== null) {
                $named = ['background' => null, 'rim' => null, 'ball' => null];
                foreach (SiteMediaAsset::query()->where('folder_id', $folder->getKey())
                    ->whereNotNull('current_media_id')->with('currentMedia')
                    ->orderByDesc('id')->get() as $asset) {
                    $title = Str::lower(trim((string) $asset->title));
                    $filename = Str::lower(pathinfo((string) $asset->original_name, PATHINFO_FILENAME));
                    foreach (array_keys($named) as $key) {
                        if ($named[$key] === null && ($title === $key || $filename === $key)
                            && str_starts_with((string) $asset->currentMedia?->mime, 'image/')) {
                            $named[$key] = strtolower($asset->uuid);
                        }
                    }
                }
                $uuids = array_merge($uuids, array_values(array_filter($named)));
            }
        }
        $uuids = array_slice(array_values(array_unique($uuids)), 0, 150);
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
