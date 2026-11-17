<?php

namespace Tests\Unit;

use App\Modules\Content\Application\Services\HeroSliderMobileFlowPatch;
use PHPUnit\Framework\TestCase;

final class HeroSliderMobileFlowPatchTest extends TestCase
{
    private function target(): array
    {
        $types = [
            'slider-eyebrow' => 'text', 'slider-heading' => 'heading',
            'slider-accent' => 'text', 'slider-description' => 'text',
            'slider-cta' => 'button', 'slider-highlights' => 'links',
        ];
        $elements = [];
        foreach ($types as $class => $type) {
            $elements[] = [
                'name' => $class, 'type' => $type, 'class_name' => 'intro '.$class,
                'x' => 6, 'y' => 24, 'width' => 92, 'tablet_x' => 6,
                'mobile_x' => 6, 'mobile_y' => 40, 'mobile_width' => 88,
            ];
        }
        return ['layout' => 'layers', 'name' => 'portal',
            'elements' => $elements, 'background_image' => 'page-builder/hero/floor.webp'];
    }

    public function test_updates_only_mobile_flow_configuration(): void
    {
        $input = [['layout' => 'layers', 'name' => 'other'], $this->target()];
        $result = (new HeroSliderMobileFlowPatch())->apply($input);
        self::assertSame('patched', $result['result']);
        self::assertSame($input[0], $result['slides'][0]);
        $slide = $result['slides'][1];
        self::assertSame('flow', $slide['mobile_layout']);
        self::assertSame(470, $slide['mobile_min_height']);
        self::assertSame(0, $slide['elements'][0]['mobile_gap']);
        self::assertSame(12, $slide['elements'][1]['mobile_gap']);
        self::assertSame(2, $slide['elements'][2]['mobile_gap']);
        self::assertSame(20, $slide['elements'][3]['mobile_gap']);
        self::assertSame(32, $slide['elements'][4]['mobile_gap']);
        self::assertSame('hidden', $slide['elements'][5]['mobile_placement']);
        self::assertSame(40, $slide['elements'][1]['mobile_y']);
        self::assertSame(24, $slide['elements'][1]['y']);
        self::assertSame(6, $slide['elements'][1]['tablet_x']);
        self::assertSame('page-builder/hero/floor.webp', $slide['background_image']);
    }

    public function test_patch_is_idempotent_and_preserves_manual_spacing(): void
    {
        $a = $this->target();
        $a['elements'][1]['mobile_gap'] = 6;
        $p = new HeroSliderMobileFlowPatch();
        $first = $p->apply([$a]);
        self::assertSame(6, $first['slides'][0]['elements'][1]['mobile_gap']);
        $again = $p->apply($first['slides']);
        self::assertSame('already_current', $again['result']);
        self::assertSame(0, $again['changed_fields']);
        self::assertSame($first['slides'], $again['slides']);
    }

    public function test_fails_closed_for_multiple_targets_or_explicit_absolute_mode(): void
    {
        $patch = new HeroSliderMobileFlowPatch();
        self::assertSame('ambiguous_target', $patch->apply([$this->target(), $this->target()])['result']);
        $slide = $this->target();
        $slide['mobile_layout'] = 'absolute';
        self::assertSame('editor_chose_absolute', $patch->apply([$slide])['result']);
        $slide['mobile_layout'] = null;
        $slide['elements'][1]['type'] = 'image';
        self::assertSame('ambiguous_layers', $patch->apply([$slide])['result']);
    }
}
