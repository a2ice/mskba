<?php

namespace App\Modules\Content\Application\Services;

/**
 * Opts a uniquely identifiable, existing composed hero into responsive flow.
 * All other slides and all desktop/tablet coordinates remain bit-for-bit intact.
 */
final class HeroSliderMobileFlowPatch
{
    private const SPACING = [
        'slider-eyebrow' => 0,
        'slider-heading' => 12,
        'slider-accent' => 2,
        'slider-description' => 20,
        'slider-cta' => 32,
    ];

    /** @param array<int, mixed> $slides
     *  @return array{slides: array, result: string, changed_fields: int}
     */
    public function apply(array $slides): array
    {
        $found = [];

        foreach ($slides as $slideIndex => $slide) {
            if (! is_array($slide) || ($slide['layout'] ?? null) !== 'layers') {
                continue;
            }
            $matches = [];
            foreach (($slide['elements'] ?? []) as $index => $element) {
                if (! is_array($element)) {
                    continue;
                }
                $classes = preg_split('/\s+/', trim((string) ($element['class_name'] ?? ''))) ?: [];
                foreach (array_keys(self::SPACING) as $class) {
                    if (in_array($class, $classes, true)) {
                        $matches[$class][] = $index;
                    }
                }
            }
            if (count($matches) === count(self::SPACING)) {
                $found[] = [$slideIndex, $matches];
            }
        }

        if (count($found) !== 1) {
            return ['slides' => $slides, 'result' => count($found) === 0
                ? 'target_not_found' : 'ambiguous_target', 'changed_fields' => 0];
        }

        [$slideIndex, $matches] = $found[0];
        $types = [
            'slider-eyebrow' => 'text', 'slider-heading' => 'heading',
            'slider-accent' => 'text', 'slider-description' => 'text',
            'slider-cta' => 'button',
        ];
        foreach ($types as $class => $type) {
            if (count($matches[$class]) !== 1
                || ($slides[$slideIndex]['elements'][$matches[$class][0]]['type'] ?? null) !== $type) {
                return ['slides' => $slides, 'result' => 'ambiguous_layers', 'changed_fields' => 0];
            }
        }

        if (isset($slides[$slideIndex]['mobile_layout'])
            && $slides[$slideIndex]['mobile_layout'] !== 'flow') {
            // Never override an editor's explicit layout choice.
            return ['slides' => $slides, 'result' => 'editor_chose_absolute', 'changed_fields' => 0];
        }

        $changed = 0;
        $put = static function (array &$data, string $key, mixed $value) use (&$changed): void {
            if (! array_key_exists($key, $data) || $data[$key] !== $value) {
                $data[$key] = $value;
                $changed++;
            }
        };

        $slide = &$slides[$slideIndex];
        $put($slide, 'mobile_layout', 'flow');
        foreach ([
            'mobile_padding_top' => 38,
            'mobile_padding_bottom' => 76,
            'mobile_min_height' => 470,
        ] as $key => $value) {
            // Existing author-adjusted settings win.
            if (! isset($slide[$key])) {
                $put($slide, $key, $value);
            }
        }

        foreach (self::SPACING as $class => $gap) {
            $layer = &$slide['elements'][$matches[$class][0]];
            if (! isset($layer['mobile_placement'])) {
                $put($layer, 'mobile_placement', 'flow');
            }
            if (! isset($layer['mobile_gap'])) {
                $put($layer, 'mobile_gap', $gap);
            }
            unset($layer);
        }

        // Existing link rails stay absent on small screens. Images remain
        // optional overlay decorations rather than occupying text flow.
        foreach ($slide['elements'] as &$element) {
            if (! is_array($element) || isset($element['mobile_placement'])) {
                continue;
            }
            if (($element['type'] ?? null) === 'links') {
                $put($element, 'mobile_placement', 'hidden');
            } elseif (($element['type'] ?? null) === 'image') {
                $put($element, 'mobile_placement', 'overlay');
            }
        }
        unset($element, $slide);

        return [
            'slides' => $slides,
            'result' => $changed > 0 ? 'patched' : 'already_current',
            'changed_fields' => $changed,
        ];
    }
}
