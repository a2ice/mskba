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

        // Critical first-viewport arena art must be paintable on the first HTML
        // frame, even when PHP media routes have a slow cold response. Use only
        // already-generated, bounded WebP thumbnails; never inline originals.
        $inlined = $this->inlineCriticalPreviews($html, $previews);

        // Handle mobile <picture><source srcset="..."> as well as all <img>.
        // For multi-density srcset, retain the author's untouched originals.
        return preg_replace_callback('~<(img|source)\b[^>]*>~i', static function (array $found) use ($previews, $inlined): string {
            $tag = $found[0];
            $isImg = strtolower($found[1]) === 'img';
            $attribute = $isImg ? 'src' : 'srcset';
            if (! preg_match('~\b'. $attribute .'\s*=\s*(["\'])([^"\']*)\1~i', $tag, $match)) {
                return $tag;
            }
            $url = $match[2];
            // URL-only srcsets. Descriptor combinations and external media
            // are not altered.
            if (! preg_match('~^/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})$~i', $url, $uuid)) {
                return $tag;
            }
            $preview = $previews[strtolower($uuid[1])] ?? null;
            if (! is_string($preview) || $preview !== '/media/site/'.strtolower($uuid[1]).'/lqip') {
                return $tag;
            }

            // Remove older image enhancer metadata: this element already
            // renders its LQIP natively and needs no overlay clone.
            $initial = preg_replace('~\sdata-site-media-lqip(?:-mobile)?\s*=\s*(["\']).*?\1~i', '', $tag);
            $isCritical = $isImg && self::isCriticalImage($tag);
            $firstPaintUrl = $isCritical ? ($inlined[strtolower($uuid[1])] ?? $preview) : $preview;
            $initial = preg_replace(
                '~\b'.$attribute.'\s*=\s*(["\'])'.preg_quote($url, '~').'\1~i',
                $attribute.'="'.e($firstPaintUrl).'"', $initial, 1,
            );
            if ($isImg) {
                // Prevent CSS from delaying venues player preview visibility.
                // The image remains a single element with CSS-authored geometry.
                $extra = ' data-mskba-lqip-full-src="'.e($url).'"';
                if ($isCritical || preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bmskba-v2-venues__player\b[^"\']*\1~i', $tag)) {
                    $initial = preg_replace('~\s(?:loading|fetchpriority)\s*=\s*(["\']).*?\1~i', '', $initial);
                    $extra .= ' data-mskba-lqip-venue-player="1" loading="eager" fetchpriority="high"';
                }
            } else {
                $extra = ' data-mskba-lqip-full-srcset="'.e($url).'"';
            }

            $initial = preg_replace('~\s*/?>$~', $extra.'>', $initial, 1);
            if (! $isImg) {
                return $initial;
            }

            // Real original remains available without JS (including when
            // screen readers or crawlers request the HTML without hydration).
            $fallbackTag = preg_replace('~\sdata-site-media-lqip(?:-mobile)?\s*=\s*(["\']).*?\1~i', '', $tag);
            return $initial.'<noscript>'.$fallbackTag.'</noscript>';
        }, $html) ?? $html;
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

        // Keep all CSS geometry, animation, gradients and selector precedence
        // unchanged; only the URL token becomes a tiny first-paint image.
        return preg_replace_callback(
            '~url\(\s*(["\']?)(/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}))\1\s*\)~i',
            static function (array $match) use ($previews): string {
                $preview = $previews[strtolower($match[3])] ?? null;
                return $preview ? 'url("'.e($preview).'")' : $match[0];
            },
            $css,
        ) ?? $css;
    }
}
