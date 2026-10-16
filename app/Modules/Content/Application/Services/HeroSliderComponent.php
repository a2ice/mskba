<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class HeroSliderComponent
{
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

        $prepared = array_map(function (array $slide): array {
            foreach (['background_desktop', 'background_mobile', 'near_player', 'far_player'] as $key) {
                $slide[$key.'_url'] = $this->mediaUrl($slide[$key] ?? null);
                if ($key === 'background_desktop' && $slide[$key.'_url'] === null) {
                    $slide[$key.'_url'] = asset('images/bg-indoor.png');
                }
            }

            foreach (['far_x' => 45, 'near_x' => 72, 'far_width' => 18, 'near_width' => 34,
                'far_mobile_x' => 22, 'near_mobile_x' => 75] as $key => $fallback) {
                $slide[$key] = max(0, min(100, (float) ($slide[$key] ?? $fallback)));
            }

            return $slide;
        }, $slides);

        return view('page-builder.components.hero-slider', [
            'slides' => $prepared,
            'options' => HeroSliderSettings::options($options),
            'sliderId' => 'mskba-hero-'.$component->getKey(),
        ])->render();
    }

    private function mediaUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        // All uploaded paths are checked by HeroSliderSettings before use.
        return Storage::disk('public')->url($path);
    }
}
