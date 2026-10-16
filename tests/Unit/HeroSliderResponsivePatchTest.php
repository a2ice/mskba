<?php

namespace Tests\Unit;

use App\Modules\Content\Application\Services\HeroSliderResponsivePatch;
use PHPUnit\Framework\TestCase;

final class HeroSliderResponsivePatchTest extends TestCase
{
    private function slide(): array
    {
        $types = [
            'slider-eyebrow' => 'text', 'slider-heading' => 'heading',
            'slider-accent' => 'text', 'slider-description' => 'text',
            'slider-cta' => 'button', 'slider-highlights' => 'links',
        ];
        $elements = [];
        foreach ($types as $class => $type) {
            $elements[] = [
                'type' => $type, 'name' => $class,
                'class_name' => 'other '.$class,
                'content' => 'untouched', 'x' => 41, 'y' => 42, 'width' => 43,
                'mobile_x' => 1, 'mobile_y' => 2, 'mobile_width' => 70,
            ];
        }

        return ['layout' => 'layers', 'name' => 'test', 'elements' => $elements];
    }

    public function test_patches_only_the_requested_responsive_fields(): void
    {
        $slides = [['layout' => 'legacy', 'title' => 'keep'], $this->slide()];
        $result = (new HeroSliderResponsivePatch())->apply($slides);
        self::assertSame('patched', $result['result']);
        self::assertSame(33, $result['changed_fields']);
        self::assertSame($slides[0], $result['slides'][0]);
        $elements = $result['slides'][1]['elements'];
        self::assertSame([6, 12, 88], array_values(array_intersect_key($elements[0], array_flip(['tablet_x','tablet_y','tablet_width']))));
        self::assertSame(31, $elements[2]['mobile_y']);
        self::assertSame(55, $elements[4]['mobile_width']);
        self::assertSame(42, $elements[5]['tablet_x']);
        self::assertSame(69, $elements[5]['tablet_y']);
        self::assertSame(54, $elements[5]['tablet_width']);
        self::assertSame(1, $elements[5]['mobile_x']); // hidden mobile highlight
        self::assertSame(41, $elements[2]['x']); // desktop untouched
        self::assertSame('untouched', $elements[1]['content']);
    }

    public function test_patch_is_idempotent(): void
    {
        $patch = new HeroSliderResponsivePatch();
        $first = $patch->apply([$this->slide()]);
        $second = $patch->apply($first['slides']);
        self::assertSame('already_current', $second['result']);
        self::assertSame(0, $second['changed_fields']);
        self::assertSame($first['slides'], $second['slides']);
    }

    public function test_fail_closed_for_missing_or_ambiguous_slides_and_types(): void
    {
        $patch = new HeroSliderResponsivePatch();
        self::assertSame('no_unique_target_found', $patch->apply([['layout' => 'legacy']])['result']);
        self::assertSame('ambiguous_slides', $patch->apply([$this->slide(), $this->slide()])['result']);
        $wrong = $this->slide();
        $wrong['elements'][1]['type'] = 'text';
        self::assertSame('unexpected_layer_type', $patch->apply([$wrong])['result']);
    }

    public function test_allows_unclassified_single_links_layer(): void
    {
        $slide = $this->slide();
        $slide['elements'][5]['class_name'] = '';
        $result = (new HeroSliderResponsivePatch())->apply([$slide]);
        self::assertSame('patched', $result['result']);
    }
}
