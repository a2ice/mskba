<?php

namespace App\Modules\Content\Application\Services;

/**
 * Converts one old, fixed-layout Hero Slider JSON record into typed layers.
 * It preserves slide visibility, targeting, all content, media paths and links.
 * Original JSON must be backed up by the caller before this conversion is saved.
 */
final class HeroSliderLegacySlideConverter
{
    /** @param array<int, mixed> $slides
     *  @return array{slides: array, converted: int}
     */
    public function convert(array $slides): array
    {
        $count = 0;
        foreach ($slides as $index => $slide) {
            if (! is_array($slide)) {
                throw new \UnexpectedValueException('Malformed legacy slide.');
            }
            if (($slide['layout'] ?? 'legacy') === 'layers') {
                continue;
            }
            if (($slide['layout'] ?? 'legacy') !== 'legacy') {
                throw new \UnexpectedValueException('Unknown legacy layout.');
            }
            $slides[$index] = $this->convertOne($slide);
            $count++;
        }

        return ['slides' => $slides, 'converted' => $count];
    }

    private function convertOne(array $old): array
    {
        $heading = trim((string) ($old['title'] ?? ''));
        if ($heading === '') {
            throw new \UnexpectedValueException('Legacy slide has no heading.');
        }
        $ctaLabel = trim((string) ($old['cta_label'] ?? ''));
        $ctaUrl = trim((string) ($old['cta_url'] ?? ''));
        if ($ctaLabel === '' || $ctaUrl === '') {
            throw new \UnexpectedValueException('Legacy slide has no CTA.');
        }

        $lineCount = min(3, max(1, substr_count($heading, "\n") + 1));
        $accentY = 22 + $lineCount * 11;
        $descriptionY = $accentY + (trim((string) ($old['accent_title'] ?? '')) === '' ? 0 : 12);
        $actionsY = max(69, min(83, $descriptionY + 18));
        $copyDelay = (int) ($old['delay_copy_ms'] ?? 440);
        $layers = [];

        $push = static function (array $layer) use (&$layers): void {
            $layers[] = $layer + [
                'animation' => 'fade', 'delay_ms' => 0, 'duration_ms' => 700,
                'mobile_x' => 6, 'mobile_y' => 20, 'mobile_width' => 88,
                'z_index' => 4, 'align' => 'left',
            ];
        };

        if (trim((string) ($old['eyebrow'] ?? '')) !== '') {
            $push([
                'type' => 'text', 'name' => 'Надпись', 'class_name' => 'slider-eyebrow',
                'content' => (string) $old['eyebrow'], 'x' => 6, 'y' => 15, 'width' => 85,
                'tablet_x' => 6, 'tablet_y' => 12, 'tablet_width' => 88,
                'mobile_y' => 9, 'animation' => $old['animation_copy'] ?? 'slide-up',
                'delay_ms' => $copyDelay,
            ]);
        }

        $push([
            'type' => 'heading', 'name' => 'Основной заголовок', 'class_name' => 'slider-heading',
            'content' => $heading, 'x' => 6, 'y' => 22, 'width' => 83,
            'tablet_x' => 6, 'tablet_y' => 20, 'tablet_width' => 92,
            'mobile_y' => 16, 'mobile_width' => 92,
            'animation' => $old['animation_copy'] ?? 'slide-up', 'delay_ms' => $copyDelay,
        ]);

        if (trim((string) ($old['accent_title'] ?? '')) !== '') {
            $push([
                'type' => 'text', 'name' => 'Акцент', 'class_name' => 'slider-accent',
                'content' => (string) $old['accent_title'],
                'x' => 6, 'y' => $accentY, 'width' => 90,
                'tablet_x' => 6, 'tablet_y' => $accentY, 'tablet_width' => 92,
                'mobile_y' => 31 + max(0, $lineCount - 1) * 12, 'mobile_width' => 92,
                'animation' => $old['animation_copy'] ?? 'slide-up', 'delay_ms' => $copyDelay,
            ]);
        }

        if (trim((string) ($old['description'] ?? '')) !== '') {
            $push([
                'type' => 'text', 'name' => 'Описание', 'class_name' => 'slider-description',
                'content' => (string) $old['description'],
                'x' => 6, 'y' => $descriptionY, 'width' => 45,
                'tablet_x' => 6, 'tablet_y' => min(65, $descriptionY), 'tablet_width' => 78,
                'mobile_y' => min(65, $descriptionY + 8), 'mobile_width' => 88,
                'animation' => $old['animation_copy'] ?? 'slide-up', 'delay_ms' => $copyDelay,
            ]);
        }

        $push([
            'type' => 'button', 'name' => 'Кнопка', 'class_name' => 'slider-cta',
            'content' => $ctaLabel, 'url' => $ctaUrl, 'show_arrow' => true,
            'x' => 6, 'y' => $actionsY, 'width' => 25,
            'tablet_x' => 6, 'tablet_y' => $actionsY, 'tablet_width' => 30,
            'mobile_y' => 74, 'mobile_width' => 55,
            'animation' => $old['animation_cta'] ?? 'slide-up',
            'delay_ms' => (int) ($old['delay_cta_ms'] ?? 1450),
        ]);

        if (! empty($old['highlights']) && is_array($old['highlights'])) {
            $push([
                'type' => 'links', 'name' => 'Разделы портала',
                'class_name' => 'slider-highlights',
                'links' => array_values($old['highlights']),
                'x' => 43, 'y' => $actionsY, 'width' => 53,
                'tablet_x' => 42, 'tablet_y' => $actionsY, 'tablet_width' => 54,
                'animation' => $old['animation_highlights'] ?? 'fade',
                'delay_ms' => (int) ($old['delay_highlights_ms'] ?? 1050),
            ]);
        }

        foreach (['far', 'near'] as $position) {
            $path = $old[$position.'_player'] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }
            $width = (float) ($old[$position.'_width'] ?? ($position === 'far' ? 18 : 34));
            $center = (float) ($old[$position.'_x'] ?? ($position === 'far' ? 45 : 72));
            $mobileCenter = (float) ($old[$position.'_mobile_x'] ??
                ($position === 'far' ? 22 : 75));
            $mobileWidth = $position === 'far' ? 35 : 65;
            $push([
                'type' => 'image', 'name' => $position === 'far' ? 'Дальний игрок' : 'Ближний игрок',
                'class_name' => 'slider-player',
                'image' => $path, 'alt' => '',
                'x' => max(0, min(100, $center - $width / 2)), 'y' => 0, 'width' => $width,
                'mobile_x' => max(0, min(100, $mobileCenter - $mobileWidth / 2)),
                'mobile_y' => 0, 'mobile_width' => $mobileWidth,
                'z_index' => 2, 'animation' => $old['animation_'.$position] ??
                    ($position === 'far' ? 'slide-up' : 'zoom'),
                'delay_ms' => (int) ($old['delay_'.$position.'_ms'] ??
                    ($position === 'far' ? 240 : 460)),
            ]);
        }

        return [
            'layout' => 'layers',
            'name' => mb_substr($heading, 0, 90),
            'enabled' => (bool) ($old['enabled'] ?? true),
            'audience' => (array) ($old['audience'] ?? []),
            'background_image' => $old['background_desktop'] ?? null,
            'background_image_mobile' => $old['background_mobile'] ?? null,
            'background_preset' => empty($old['background_desktop']) ? 'indoor' : null,
            'background_effect' => $old['animation_background'] ?? 'fade',
            'scrim' => 'dark',
            'elements' => $layers,
        ];
    }
}
