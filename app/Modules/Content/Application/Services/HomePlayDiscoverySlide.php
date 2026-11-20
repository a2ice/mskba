<?php

namespace App\Modules\Content\Application\Services;

/**
 * The first Hero story explains why the other five stories exist.
 *
 * Data-only content: remains editable through the normal Filament Hero Slider
 * editor, without hard-coding an extra slide into the public renderer.
 */
final class HomePlayDiscoverySlide
{
    public const NAME = 'Найди свою игру — с кем, где и когда';

    /**
     * @param list<array<string,mixed>> $slides
     * @return list<array<string,mixed>>|null Null means the authored slider must not be changed.
     */
    public static function prependToFiveVenueFirstSlides(array $slides): ?array
    {
        if (count($slides) !== 5 || ! array_is_list($slides) || ! is_array($slides[0] ?? null)) {
            return null;
        }

        foreach ($slides as $slide) {
            if (! is_array($slide) || ($slide['name'] ?? null) === self::NAME) {
                return null;
            }
        }

        // Avoid rewriting an independently authored CMS story sequence.
        $first = $slides[0];
        $copy = implode(' ', array_filter([
            (string) ($first['name'] ?? ''),
            (string) ($first['title'] ?? ''),
            (string) ($first['accent_title'] ?? ''),
            ...array_map(
                static fn ($element): string => is_array($element) ? (string) ($element['content'] ?? '') : '',
                is_array($first['elements'] ?? null) ? $first['elements'] : [],
            ),
        ]));
        if (! str_contains(mb_strtolower($copy), 'площадк')) {
            return null;
        }

        array_unshift($slides, self::slide());

        return $slides;
    }

    /** @return array<string,mixed> */
    public static function slide(): array
    {
        $layer = static function (
            string $type,
            string $name,
            string $class,
            string $content,
            float $x,
            float $y,
            float $width,
            float $tabletY,
            float $tabletWidth,
            int $mobileGap,
            ?string $url = null
        ): array {
            $item = [
                'type' => $type,
                'name' => $name,
                'class_name' => $class,
                'content' => $content,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'tablet_x' => 6,
                'tablet_y' => $tabletY,
                'tablet_width' => $tabletWidth,
                'mobile_x' => 6,
                'mobile_y' => $y,
                'mobile_width' => 90,
                'mobile_placement' => 'flow',
                'mobile_gap' => $mobileGap,
                'animation' => 'slide-up',
                'delay_ms' => 140,
                'duration_ms' => 750,
                'z_index' => 4,
                'align' => 'left',
            ];
            if ($url !== null) {
                $item['url'] = $url;
                $item['show_arrow'] = true;
            }

            return $item;
        };

        return [
            'layout' => 'layers',
            'name' => self::NAME,
            'enabled' => true,
            'audience' => ['auth' => 'all'],
            // An existing first-party arena backdrop; no new artwork, uploads,
            // external dependencies or duplicate photography.
            'background_preset' => 'indoor',
            'background_effect' => 'fade',
            'scrim' => 'dark',
            'mobile_layout' => 'flow',
            'mobile_padding_top' => 52,
            'mobile_padding_bottom' => 104,
            'mobile_min_height' => 600,
            'elements' => [
                $layer('text', 'Вступление', 'slider-eyebrow',
                    'ТВОЯ ИГРА НАЧИНАЕТСЯ ЗДЕСЬ', 6, 18, 85, 16, 88, 0),
                $layer('heading', 'Главный посыл', 'slider-heading',
                    'НАЙДИ СВОЮ ИГРУ', 6, 28, 89, 27, 92, 18),
                $layer('text', 'С кем, где, когда', 'slider-accent',
                    'С КЕМ. ГДЕ. КОГДА.', 6, 41, 92, 43, 94, 10),
                $layer('text', 'Описание подбора', 'slider-description',
                    'Укажи, где, когда и в каком формате тебе удобно играть. MSKBA поможет найти подходящие площадки и игроков с похожими интересами.',
                    6, 58, 61, 60, 78, 22),
                $layer('button', 'Настроить интерес', 'slider-cta',
                    'Хочу играть', 6, 77, 29, 81, 40, 28, '/account/subscriptions'),
            ],
        ];
    }
}
