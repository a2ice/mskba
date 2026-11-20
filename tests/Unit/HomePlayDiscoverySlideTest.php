<?php

namespace Tests\Unit;

use App\Modules\Content\Application\Services\HomePlayDiscoverySlide;
use PHPUnit\Framework\TestCase;

final class HomePlayDiscoverySlideTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function fiveSlides(): array
    {
        return [
            ['layout' => 'layers', 'name' => 'Площадки', 'elements' => [
                ['type' => 'heading', 'content' => 'ПЛОЩАДКИ'],
            ]],
            ['name' => 'Игры', 'elements' => []],
            ['name' => 'Команды', 'elements' => []],
            ['name' => 'Форма', 'elements' => []],
            ['name' => 'Рейтинги', 'elements' => []],
        ];
    }

    public function test_it_adds_a_general_story_before_the_existing_five_without_mutating_them(): void
    {
        $original = $this->fiveSlides();
        $actual = HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($original);

        self::assertNotNull($actual);
        self::assertCount(6, $actual);
        self::assertSame(HomePlayDiscoverySlide::NAME, $actual[0]['name']);
        self::assertSame($original, array_slice($actual, 1));
        self::assertSame('/account/subscriptions', $actual[0]['elements'][4]['url']);
        self::assertSame('flow', $actual[0]['mobile_layout']);
    }

    public function test_it_does_not_duplicate_the_intro_or_replace_an_authored_sequence(): void
    {
        $actual = HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($this->fiveSlides());
        self::assertNotNull($actual);
        self::assertNull(HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($actual));

        $custom = $this->fiveSlides();
        $custom[0]['name'] = 'Авторский вводный слайд';
        $custom[0]['elements'][0]['content'] = 'ВСТУПЛЕНИЕ';
        self::assertNull(HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($custom));
        self::assertNull(HomePlayDiscoverySlide::prependToFiveVenueFirstSlides([]));
    }

    public function test_intro_promises_discovery_not_an_automatic_booking_or_confirmed_game(): void
    {
        $slide = HomePlayDiscoverySlide::slide();
        $copy = implode(' ', array_column($slide['elements'], 'content'));

        self::assertStringContainsString('похожими интересами', $copy);
        self::assertStringNotContainsString('автоматически заброниру', $copy);
        self::assertStringNotContainsString('игра собрана', $copy);
        self::assertCount(5, $slide['elements']);
    }
}
