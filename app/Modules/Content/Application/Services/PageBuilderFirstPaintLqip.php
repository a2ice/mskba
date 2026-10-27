<?php

namespace App\Modules\Content\Application\Services;

/**
 * Native first-paint LQIP for the CSS-positioned homepage venues player.
 *
 * The player <img> itself starts with the mini WebP as src. Its original
 * class and layout are unchanged, so scene CSS and parallax keep targeting
 * the same DOM element. No JS-inserted absolutely positioned overlay.
 */
final class PageBuilderFirstPaintLqip
{
    /** @param array<string,string> $previews */
    public function apply(string $html, array $previews): string
    {
        if (! str_contains($html, 'mskba-v2-venues__player') || $previews === []) {
            return $html;
        }

        return preg_replace_callback('~<img\b[^>]*>~i', static function (array $match) use ($previews): string {
            $tag = $match[0];
            if (! preg_match('~\bclass\s*=\s*(["\'])([^"\']*)\1~i', $tag, $classes)
                || ! preg_match('~(?:^|\s)mskba-v2-venues__player(?:\s|$)~', $classes[2])) {
                return $tag;
            }

            if (! preg_match('~\bsrc\s*=\s*(["\'])(/media/site/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}))\1~i', $tag, $src)) {
                return $tag;
            }

            $preview = $previews[strtolower($src[3])] ?? null;
            if (! is_string($preview) || ! preg_match(
                '~^/media/site/'.preg_quote($src[3], '~').'/lqip$~i', $preview,
            )) {
                return $tag;
            }

            // The editor HTML has already been sanitized by PageBuilderMarkup.
            // Only server-verified same-origin media URLs are injected.
            $initial = preg_replace(
                '~\bsrc\s*=\s*(["\'])'.preg_quote($src[2], '~').'\1~i',
                'src="'.e($preview).'"', $tag, 1,
            );
            $initial = preg_replace('~\sloading\s*=\s*(["\']).*?\1~i', '', $initial);
            $initial = preg_replace('~\s*/?>$~', ' data-mskba-player-full-src="'.e($src[2]).'" data-mskba-player-lqip="1" loading="eager">', $initial, 1);
            // HTML <img> can now paint before JavaScript initializes. Keep the
            // original high-resolution fallback if scripts are disabled.
            return $initial.'<noscript><style>.mskba-v2-venues__player[data-mskba-player-lqip]{display:none!important}</style>'
                .$tag.'</noscript>';
        }, $html) ?? $html;
    }
}
