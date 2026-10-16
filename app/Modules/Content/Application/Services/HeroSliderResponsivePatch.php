<?php

namespace App\Modules\Content\Application\Services;

/**
 * A one-off, data-only, development-hero coordinate patch.
 * Never overwrites the desktop layout, text, images, roles or animation.
 * Fail closed if a unique layer-based hero cannot be identified.
 */
final class HeroSliderResponsivePatch
{
    private const POSITIONS = [
        'slider-eyebrow' => [
            'tablet_x' => 6, 'tablet_y' => 12, 'tablet_width' => 88,
            'mobile_x' => 6, 'mobile_y' => 9, 'mobile_width' => 88,
        ],
        'slider-heading' => [
            'tablet_x' => 6, 'tablet_y' => 20, 'tablet_width' => 92,
            'mobile_x' => 6, 'mobile_y' => 16, 'mobile_width' => 92,
        ],
        'slider-accent' => [
            'tablet_x' => 6, 'tablet_y' => 29, 'tablet_width' => 92,
            'mobile_x' => 6, 'mobile_y' => 31, 'mobile_width' => 92,
        ],
        'slider-description' => [
            'tablet_x' => 6, 'tablet_y' => 45, 'tablet_width' => 78,
            'mobile_x' => 6, 'mobile_y' => 49, 'mobile_width' => 88,
        ],
        'slider-cta' => [
            'tablet_x' => 6, 'tablet_y' => 69, 'tablet_width' => 30,
            'mobile_x' => 6, 'mobile_y' => 68, 'mobile_width' => 55,
        ],
        'slider-highlights' => [
            'tablet_x' => 42, 'tablet_y' => 69, 'tablet_width' => 54,
            // Hidden in mobile CSS; don't edit its unused mobile values.
        ],
    ];

    /**
     * @param array<int, array<string, mixed>> $slides
     * @return array{slides: array, result: string, changed_fields: int}
     */
    public function apply(array $slides): array
    {
        $targets = [];

        foreach ($slides as $slideIndex => $slide) {
            if (! is_array($slide) || ($slide['layout'] ?? 'legacy') !== 'layers'
                || ! is_array($slide['elements'] ?? null)) {
                continue;
            }

            $indices = [];
            foreach ($slide['elements'] as $index => $element) {
                if (! is_array($element)) {
                    continue;
                }
                $classes = preg_split('/\\s+/', trim((string) ($element['class_name'] ?? ''))) ?: [];
                foreach (self::POSITIONS as $class => $position) {
                    if (in_array($class, $classes, true)) {
                        if (isset($indices[$class])) {
                            $indices[$class] = -1; // duplicate = ambiguous
                        } else {
                            $indices[$class] = $index;
                        }
                    }
                }
            }
            if (isset($indices['slider-eyebrow'], $indices['slider-heading'],
                $indices['slider-accent'], $indices['slider-description'],
                $indices['slider-cta'])) {
                // A single links element can replace the highlight class, as
                // the author was not required to assign that optional class.
                if (! isset($indices['slider-highlights'])) {
                    $links = [];
                    foreach ($slide['elements'] as $index => $element) {
                        if (is_array($element) && ($element['type'] ?? null) === 'links') {
                            $links[] = $index;
                        }
                    }
                    if (count($links) === 1) {
                        $indices['slider-highlights'] = $links[0];
                    }
                }
                $targets[] = [$slideIndex, $indices];
            }
        }

        if (count($targets) !== 1) {
            return [
                'slides' => $slides,
                'result' => $targets === [] ? 'no_unique_target_found' : 'ambiguous_slides',
                'changed_fields' => 0,
            ];
        }

        [$slideIndex, $indices] = $targets[0];
        if (count($indices) !== count(self::POSITIONS)
            || in_array(-1, $indices, true)
            || count(array_unique(array_values($indices))) !== count(self::POSITIONS)) {
            return ['slides' => $slides, 'result' => 'incomplete_or_ambiguous_layers', 'changed_fields' => 0];
        }

        $types = [
            'slider-eyebrow' => 'text', 'slider-heading' => 'heading',
            'slider-accent' => 'text', 'slider-description' => 'text',
            'slider-cta' => 'button', 'slider-highlights' => 'links',
        ];
        foreach ($types as $class => $type) {
            if (($slides[$slideIndex]['elements'][$indices[$class]]['type'] ?? null) !== $type) {
                return ['slides' => $slides, 'result' => 'unexpected_layer_type', 'changed_fields' => 0];
            }
        }

        $changed = 0;
        foreach (self::POSITIONS as $class => $position) {
            $index = $indices[$class];
            foreach ($position as $key => $value) {
                if (($slides[$slideIndex]['elements'][$index][$key] ?? null) !== $value) {
                    $slides[$slideIndex]['elements'][$index][$key] = $value;
                    $changed++;
                }
            }
        }

        return [
            'slides' => $slides,
            'result' => $changed === 0 ? 'already_current' : 'patched',
            'changed_fields' => $changed,
        ];
    }
}
