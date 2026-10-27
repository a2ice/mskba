<?php

namespace App\Modules\Content\Application\Services;

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

        // Handle mobile <picture><source srcset="..."> as well as all <img>.
        // For multi-density srcset, retain the author's untouched originals.
        return preg_replace_callback('~<(img|source)\b[^>]*>~i', static function (array $found) use ($previews): string {
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
            $initial = preg_replace(
                '~\b'.$attribute.'\s*=\s*(["\'])'.preg_quote($url, '~').'\1~i',
                $attribute.'="'.e($preview).'"', $initial, 1,
            );
            if ($isImg) {
                // Prevent CSS from delaying venues player preview visibility.
                // The image remains a single element with CSS-authored geometry.
                $extra = ' data-mskba-lqip-full-src="'.e($url).'"';
                if (preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bmskba-v2-venues__player\b[^"\']*\1~i', $tag)) {
                    $initial = preg_replace('~\sloading\s*=\s*(["\']).*?\1~i', '', $initial);
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
            return $initial.'<noscript>'.$tag.'</noscript>';
        }, $html) ?? $html;
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
