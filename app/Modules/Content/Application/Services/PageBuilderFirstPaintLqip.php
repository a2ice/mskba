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

        // Browser-first progressive rendering: start every image with its
        // ORIGINAL URL. An LQIP is only a CSS background underneath the same
        // image while it is pending. When the original is in HTTP cache, the
        // browser paints it directly on refresh; it never swaps src to LQIP.
        // <picture><source> keeps the real responsive srcset throughout.
        return preg_replace_callback('~<img\b[^>]*>~i', static function (array $found) use ($previews, $inlined): string {
            $tag = $found[0];
            if (! preg_match('~\bsrc\s*=\s*(["\'])([^"\']*)\1~i', $tag, $match)) {
                return $tag;
            }
            $url = $match[2];
            if (! preg_match('~^/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})$~i', $url, $uuid)) {
                return $tag;
            }
            $preview = $previews[strtolower($uuid[1])] ?? null;
            if (! is_string($preview) || $preview !== '/media/site/'.strtolower($uuid[1]).'/lqip') {
                return $tag;
            }

            // Native background-only preview: preserve element geometry and
            // author style, remove legacy clone-based enhancer metadata.
            $initial = preg_replace('~\sdata-site-media-lqip(?:-mobile)?\s*=\s*(["\']).*?\1~i', '', $tag);
            $isCritical = self::isCriticalImage($tag);
            $firstPaintPreview = $isCritical ? ($inlined[strtolower($uuid[1])] ?? $preview) : $preview;
            $backgroundSize = preg_match('~\bclass\s*=\s*(["\'])[^"\']*\b(?:mskba-v3-venues__arena|mskba-hero-slide__background)\b[^"\']*\1~i', $tag)
                ? 'cover' : 'contain';
            $style = 'background-image:url('.$firstPaintPreview.');background-size:'.$backgroundSize.';background-position:center;background-repeat:no-repeat;';
            if (preg_match('~\bstyle\s*=\s*(["\'])(.*?)\1~is', $initial)) {
                $initial = preg_replace_callback('~\bstyle\s*=\s*(["\'])(.*?)\1~is', static function (array $existing) use ($style): string {
                    return 'style='.$existing[1].rtrim($existing[2], ';').';'.$style.$existing[1];
                }, $initial, 1);
            } else {
                $initial = preg_replace('~\s*/?>$~', ' style="'.e($style).'">', $initial, 1);
            }

            $extra = ' data-mskba-lqip-full-src="'.e($url).'" data-mskba-lqip-preview-src="'.e($preview).'"';
            if ($isCritical || preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bmskba-v2-venues__player\b[^"\']*\1~i', $tag)) {
                $initial = preg_replace('~\s(?:loading|fetchpriority)\s*=\s*(["\']).*?\1~i', '', $initial);
                $extra .= ' data-mskba-lqip-venue-player="1" loading="eager" fetchpriority="high"';
            }

            return preg_replace('~\s*/?>$~', $extra.'>', $initial, 1);
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

        // Paint the original CSS background ABOVE its tiny LQIP. The browser
        // displays the miniature only if the original has not arrived yet.
        // A cache-warm original paints immediately on refresh with no JS
        // replacement or placeholder-first phase. Keep other CSS properties,
        // masks and multi-image gradients unchanged.
        return preg_replace_callback('~\bbackground-image\s*:\s*([^;}]+)~i', static function (array $declaration) use ($previews): string {
            $new = preg_replace_callback(
                '~url\(\s*(["\']?)(/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}))\1\s*\)~i',
                static function (array $url) use ($previews): string {
                    $preview = $previews[strtolower($url[3])] ?? null;
                    return $preview
                        ? 'url("'.e($url[2]).'"),url("'.e($preview).'")'
                        : $url[0];
                },
                $declaration[1],
            );
            return str_replace($declaration[1], $new ?? $declaration[1], $declaration[0]);
        }, $css) ?? $css;
    }
}
