<?php

namespace Tests\Unit;

use App\Modules\Content\Application\Services\HeroSliderLegacySlideConverter;
use PHPUnit\Framework\TestCase;

final class HeroSliderLegacySlideConverterTest extends TestCase
{
    private function legacy(): array
    {
        return [
            'layout' => 'legacy', 'enabled' => false,
            'name' => 'previous', 'title' => "ИГРАЙ В БАСКЕТБОЛ",
            'eyebrow' => 'Ассоциация', 'accent_title' => 'ГДЕ И КОГДА УДОБНО',
            'description' => 'Играй рядом',
            'cta_label' => 'О портале', 'cta_url' => '/about',
            'background_desktop' => 'page-builder/hero/arena.webp',
            'far_player' => 'page-builder/hero/player.png',
            'far_x' => 50, 'far_width' => 20,
            'highlights' => [['icon'=>'court', 'label'=>'Площадки', 'url'=>'/venues']],
            'audience' => ['auth' => 'authenticated'],
        ];
    }

    public function test_converts_content_media_audience_visibility_and_layout(): void
    {
        $legacy = $this->legacy();
        $converter = new HeroSliderLegacySlideConverter();
        $result = $converter->convert([$legacy]);
        self::assertSame(1, $result['converted']);
        $slide = $result['slides'][0];
        self::assertSame('layers', $slide['layout']);
        self::assertFalse($slide['enabled']);
        self::assertSame($legacy['audience'], $slide['audience']);
        self::assertSame('page-builder/hero/arena.webp', $slide['background_image']);
        $layers = $slide['elements'];
        self::assertCount(7, $layers);
        self::assertSame(['slider-eyebrow', 'slider-heading', 'slider-accent', 'slider-description', 'slider-cta', 'slider-highlights', 'slider-player'],
            array_column($layers, 'class_name'));
        self::assertSame('ИГРАЙ В БАСКЕТБОЛ', $layers[1]['content']);
        self::assertSame('/about', $layers[4]['url']);
        self::assertSame('/venues', $layers[5]['links'][0]['url']);
        self::assertSame('page-builder/hero/player.png', $layers[6]['image']);
        self::assertSame(40.0, $layers[6]['x']);
        self::assertSame(20.0, $layers[6]['width']);
    }

    public function test_keeps_generic_slides_unchanged_and_is_idempotent(): void
    {
        $slide = ['layout' => 'layers', 'elements' => [['type' => 'text', 'name' => 'x']]];
        $converter = new HeroSliderLegacySlideConverter();
        $first = $converter->convert([$this->legacy(), $slide]);
        self::assertSame(1, $first['converted']);
        self::assertSame($slide, $first['slides'][1]);
        self::assertSame(0, $converter->convert($first['slides'])['converted']);
        self::assertSame($first['slides'], $converter->convert($first['slides'])['slides']);
    }

    public function test_invalid_legacy_cannot_be_silently_discarded(): void
    {
        $legacy = $this->legacy();
        unset($legacy['title']);
        $this->expectException(\UnexpectedValueException::class);
        (new HeroSliderLegacySlideConverter())->convert([$legacy]);
    }

    public function test_empty_background_uses_public_builtin_arena_preset(): void
    {
        $slide = $this->legacy();
        $slide['background_desktop'] = null;
        $converted = (new HeroSliderLegacySlideConverter())->convert([$slide])['slides'][0];
        self::assertSame('indoor', $converted['background_preset']);
        self::assertNull($converted['background_image']);
    }
}
