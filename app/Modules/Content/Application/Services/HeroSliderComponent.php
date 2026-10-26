<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class HeroSliderComponent
{
    public function __construct(private readonly HeroSliderAudience $audience) {}

    /** Render only the application's trusted Blade view, never editor HTML/JS. */
    public function render(PageBuilderComponent $component, bool $preview = false): string
    {
        $slides = $preview ? $component->draft_slides : $component->published_slides;
        $options = $preview ? $component->draft_options : $component->published_options;

        try {
            HeroSliderSettings::validate($slides, $options);
        } catch (ValidationException) {
            return $preview
                ? '<p class="mskba-builder-unavailable">Заполни и сохрани корректные настройки Hero Slider.</p>'
                : '';
        }

        $slides = array_values(array_filter($slides, fn (array $slide): bool => $this->audience->visible($slide, $preview)));
        if ($slides === []) {
            return '';
        }

        $prepared = array_map(function (array $slide): array {
            if (($slide['layout'] ?? 'legacy') === 'layers') {
                $slide['background_desktop_url'] = $this->siteAssetUrl($slide['background_image_site_asset'] ?? null)
                    ?? $this->mediaUrl($slide['background_image'] ?? null)
                    ?? (($slide['background_preset'] ?? null) === 'indoor' ? asset('images/bg-indoor.png') : null);
                $slide['background_mobile_url'] = $this->siteAssetUrl($slide['background_image_mobile_site_asset'] ?? null)
                    ?? $this->mediaUrl($slide['background_image_mobile'] ?? null);
                $slide['background_desktop_lqip_url'] = ! empty($slide['background_image_site_asset'])
                    ? $this->siteAssetLqipUrl($slide['background_image_site_asset'])
                    : $this->mediaLqipUrl($slide['background_image'] ?? null);
                $slide['background_mobile_lqip_url'] = ! empty($slide['background_image_mobile_site_asset'])
                    ? $this->siteAssetLqipUrl($slide['background_image_mobile_site_asset'])
                    : $this->mediaLqipUrl($slide['background_image_mobile'] ?? null);
                $slide['delay_background_ms'] = (int) ($slide['delay_background_ms'] ?? 0);
                $slide['mobile_layout'] = $slide['mobile_layout'] ?? 'absolute';
                $slide['mobile_padding_top'] = (int) ($slide['mobile_padding_top'] ?? 38);
                $slide['mobile_padding_bottom'] = (int) ($slide['mobile_padding_bottom'] ?? 76);
                $slide['mobile_min_height'] = (int) ($slide['mobile_min_height'] ?? 470);
                $slide['elements'] = array_map(function (array $element): array {
                    $element['image_url'] = $this->siteAssetUrl($element['image_site_asset'] ?? null)
                        ?? $this->mediaUrl($element['image'] ?? null);
                    $element['image_lqip_url'] = ! empty($element['image_site_asset'])
                        ? $this->siteAssetLqipUrl($element['image_site_asset'])
                        : $this->mediaLqipUrl($element['image'] ?? null);
                    if (($element['type'] ?? '') === 'links') {
                        $element['links'] = array_map(function (array $link): array {
                            $link['icon_url'] = ($link['icon'] ?? null) === 'custom'
                                ? ($this->siteAssetUrl($link['image_site_asset'] ?? null)
                                    ?? $this->mediaUrl($link['image'] ?? null))
                                : null;

                            return $link;
                        }, $element['links'] ?? []);
                    }
                    foreach (['x' => 10, 'y' => 20, 'width' => 50,
                        'mobile_x' => 6, 'mobile_y' => 20, 'mobile_width' => 88] as $key => $fallback) {
                        $element[$key] = (float) ($element[$key] ?? $fallback);
                    }
                    foreach (['tablet_x' => 'x', 'tablet_y' => 'y', 'tablet_width' => 'width'] as $tabletKey => $desktopKey) {
                        // Existing JSON has no tablet coordinates. Fall back to
                        // the author's desktop layout without mutating stored data.
                        $element[$tabletKey] = (float) ($element[$tabletKey] ?? $element[$desktopKey]);
                    }
                    $element['delay_ms'] = (int) ($element['delay_ms'] ?? 0);
                    $element['duration_ms'] = (int) ($element['duration_ms'] ?? 700);
                    $element['z_index'] = (int) ($element['z_index'] ?? 4);
                    // Flow is opt-in per slide. Within flow layouts, images
                    // overlay by default; link rails are hidden on phones.
                    $element['mobile_placement'] = $element['mobile_placement'] ??
                        match ($element['type']) {
                            'image' => 'overlay',
                            'links' => 'hidden',
                            default => 'flow',
                        };
                    $element['mobile_gap'] = (int) ($element['mobile_gap'] ?? 12);

                    return $element;
                }, $slide['elements']);

                return $slide;
            }
            foreach (['background_desktop', 'background_mobile', 'near_player', 'far_player'] as $key) {
                $slide[$key.'_url'] = $this->mediaUrl($slide[$key] ?? null);
                $slide[$key.'_lqip_url'] = $this->mediaLqipUrl($slide[$key] ?? null);
                if ($key === 'background_desktop' && $slide[$key.'_url'] === null) {
                    $slide[$key.'_url'] = asset('images/bg-indoor.png');
                }
            }

            foreach (['far_x' => 45, 'near_x' => 72, 'far_width' => 18, 'near_width' => 34,
                'far_mobile_x' => 22, 'near_mobile_x' => 75] as $key => $fallback) {
                $slide[$key] = max(0, min(100, (float) ($slide[$key] ?? $fallback)));
            }

            foreach (['background' => 0, 'far' => 240, 'near' => 460,
                'copy' => 440, 'highlights' => 1050, 'cta' => 1450] as $part => $fallback) {
                $key = 'delay_'.$part.'_ms';
                $slide[$key] = max(0, min(5000, (int) ($slide[$key] ?? $fallback)));
            }
            return $slide;
        }, $slides);

        return view('page-builder.components.hero-slider', [
            'slides' => $prepared,
            'options' => HeroSliderSettings::options($options),
        ])->render();
    }

    private function siteAssetUrl(?string $uuid): ?string
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        return SiteMediaAsset::query()->where('uuid', $uuid)->first()?->publicUrl();
    }

    private function siteAssetLqipUrl(?string $uuid): ?string
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return SiteMediaAsset::query()->where('uuid', $uuid)->first()?->lqipUrl();
    }

    private function mediaLqipUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return SiteMediaAsset::query()->where('legacy_path', $path)->first()?->lqipUrl();
    }

    private function mediaUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        // Old JSON remains readable; imported assets gain a stable URL without changing snapshots.
        $asset = SiteMediaAsset::query()->where('legacy_path', $path)->first();
        return $asset?->publicUrl() ?? Storage::disk('public')->url($path);
    }
}
