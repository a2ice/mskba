<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * Render-time progressive media URLs for every Page Builder image, not only
 * the venues player. Never saves edited HTML/CSS back to Filament.
 */
final class PageBuilderFirstPaintLqip
{
    /** @param array<string,string> $previews */
    public function apply(string $html, array $previews): string
    {
        if ($previews === []) {
            return $html;
        }

        // The first HTML paint must request only the tiny image. Full images
        // are upgraded asynchronously after the page's initial load event.
        // This lets the homepage loader complete without waiting for originals.
        $inlined = $this->inlineCriticalPreviews($html, $previews);
        $images = preg_replace_callback('~<img\b[^>]*>~i', static function (array $found) use ($previews, $inlined): string {
            $tag = $found[0];
            if (! preg_match('~(?<![\w-])src\s*=\s*(["\'])([^"\']*)\1~i', $tag, $match)) {
                return $tag;
            }
            $url = $match[2];
            if (! preg_match('~^/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})$~i', $url, $uuid)) {
                return $tag;
            }
            $id = strtolower($uuid[1]);
            $preview = $previews[$id] ?? null;
            if (! is_string($preview) || $preview !== '/media/site/'.$id.'/lqip') {
                return $tag;
            }

            $isCritical = self::isCriticalImage($tag);
            $initialSrc = $isCritical ? ($inlined[$id] ?? $preview) : $preview;
            $initial = preg_replace('~\sdata-site-media-lqip(?:-mobile)?\s*=\s*(["\']).*?\1~i', '', $tag);
            $initial = preg_replace_callback('~(?<![\w-])src\s*=\s*(["\'])([^"\']*)\1~i',
                static fn (array $src): string => 'src='.$src[1].e($initialSrc).$src[1], $initial, 1);

            // Avoid an original being eagerly downloaded through an img srcset
            // while its ordinary src is still displaying a thumbnail.
            $originalSrcset = null;
            $initial = preg_replace_callback('~(?<![\w-])srcset\s*=\s*(["\'])([^"\']*)\1~i',
                static function (array $srcset) use ($previews, &$originalSrcset): string {
                    $candidate = trim($srcset[2]);
                    if (! preg_match('~^/media/site/([0-9a-f-]{36})(\s+\d+[wx])?$~i', $candidate, $matched)) {
                        return $srcset[0];
                    }
                    $preview = $previews[strtolower($matched[1])] ?? null;
                    if ($preview === null) {
                        return $srcset[0];
                    }
                    $originalSrcset = $srcset[2];
                    return 'srcset='.$srcset[1].e($preview.($matched[2] ?? '')).$srcset[1];
                }, $initial, 1);

            $extra = ' data-mskba-lqip-full-src="'.e($url).'" data-mskba-lqip-preview-src="'.e($preview).'"';
            if ($originalSrcset !== null) {
                $extra .= ' data-mskba-lqip-full-srcset="'.e($originalSrcset).'"';
            }
            if ($isCritical || preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bmskba-v2-venues__player\b[^"\']*\1~i', $tag)) {
                $initial = preg_replace('~\s(?:loading|fetchpriority)\s*=\s*(["\']).*?\1~i', '', $initial);
                $extra .= ' data-mskba-lqip-venue-player="1" loading="eager" fetchpriority="high"';
            }

            return preg_replace('~\s*/?>$~', $extra.'>', $initial, 1);
        }, $html) ?? $html;

        // A <picture> source must also begin with the LQIP; otherwise the
        // browser selects its original srcset ahead of the preview img.
        return preg_replace_callback('~<source\b[^>]*>~i', static function (array $found) use ($previews): string {
            $tag = $found[0];
            if (! preg_match('~(?<![\w-])srcset\s*=\s*(["\'])([^"\']*)\1~i', $tag, $match)) {
                return $tag;
            }
            $srcset = trim($match[2]);
            if (! preg_match('~^/media/site/([0-9a-f-]{36})(\s+\d+[wx])?$~i', $srcset, $source)) {
                return $tag;
            }
            $preview = $previews[strtolower($source[1])] ?? null;
            if (! $preview) {
                return $tag;
            }
            $initial = preg_replace_callback('~(?<![\w-])srcset\s*=\s*(["\'])([^"\']*)\1~i',
                static fn (array $value): string => 'srcset='.$value[1].e($preview.($source[2] ?? '')).$value[1], $tag, 1);
            return preg_replace('~\s*/?>$~', ' data-mskba-lqip-full-srcset="'.e($match[2]).'">', $initial, 1);
        }, $images) ?? $images;
    }

    /**
     * Only the visible arena scene uses embedded previews. The main image
     * remains an ordinary /media/site/ URL and is hydrated after decode.
     * Hidden layers and gallery images retain normal lazy loading.
     *
     * @param array<string,string> $previews
     * @return array<string,string> UUID => safe data URI
     */
    private function inlineCriticalPreviews(string $html, array $previews): array
    {
        preg_match_all('~<img\b[^>]*>~i', $html, $tags);
        $ids = [];
        foreach ($tags[0] ?? [] as $tag) {
            if (! self::isCriticalImage($tag)
                || ! preg_match('~\bsrc\s*=\s*(["\'])/media/site/([0-9a-f-]{36})\1~i', $tag, $found)) {
                continue;
            }
            $uuid = strtolower($found[2]);
            if (isset($previews[$uuid])) {
                $ids[] = $uuid;
            }
        }

        if ($ids === []) {
            return [];
        }

        $result = [];
        foreach (SiteMediaAsset::query()->whereIn('uuid', array_unique($ids))->with('currentMedia')->get() as $asset) {
            $uuid = strtolower((string) $asset->uuid);
            $media = $asset->currentMedia;
            if (! $media || $media->disk !== 'public') {
                continue;
            }
            $path = SiteMediaLqip::path($media);
            $disk = Storage::disk('public');
            if (! $disk->exists($path) || $disk->size($path) > SiteMediaLqip::MAX_BYTES) {
                continue;
            }
            $bytes = $disk->get($path);
            if (! is_string($bytes) || strlen($bytes) < 12
                || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
                continue;
            }
            $result[$uuid] = 'data:image/webp;base64,'.base64_encode($bytes);
        }

        return $result;
    }

    private static function isCriticalImage(string $tag): bool
    {
        if (! preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $tag, $class)) {
            return false;
        }

        return preg_match('~(?:^|\s)mskba-v3-venues__(?:arena|hoop|defender|shooter)(?:\s|$)~',
            $class[2]) === 1;
    }

    /** @param array<string,string> $previews */
    public function css(string $css, array $previews): string
    {
        if ($previews === []) {
            return $css;
        }

        // During initial load, CSS only references LQIPs. The client restores
        // full-resolution URLs above the previews AFTER the load event, so
        // CSS background originals do not hold the homepage loading layer.
        return preg_replace_callback('~\bbackground-image\s*:\s*([^;}]+)~i', static function (array $declaration) use ($previews): string {
            $new = preg_replace_callback(
                '~url\(\s*(["\']?)(/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}))\1\s*\)~i',
                static function (array $url) use ($previews): string {
                    $preview = $previews[strtolower($url[3])] ?? null;
                    return $preview
                        ? 'url("'.e($preview).'")'
                        : $url[0];
                },
                $declaration[1],
            );
            return str_replace($declaration[1], $new ?? $declaration[1], $declaration[0]);
        }, $css) ?? $css;
    }
}
