<?php

namespace Tests\Unit\Presentation\Navigation;

use App\Presentation\Navigation\ContextSubmenuResolver;
use App\Presentation\Navigation\MenuHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ContextSubmenuResolverTest extends TestCase
{
    public function test_exact_match_has_priority_over_pattern(): void
    {
        config()->set('submenu.sections', [
            [
                'exact' => ['/events/special'],
                'pattern' => null,
                'handler' => ExactSubmenu::class,
            ],
            [
                'exact' => [],
                'pattern' => '#^/events(?:/|$)#',
                'handler' => EventsPatternSubmenu::class,
            ],
        ]);

        $this->app->instance('request', Request::create('/events/special', 'GET'));

        $items = app(ContextSubmenuResolver::class)->resolve();

        self::assertSame('Exact', $items[0]['label']);
    }

    public function test_pattern_resolves_section_handler_and_filters_hidden_items(): void
    {
        config()->set('submenu.sections', [
            [
                'exact' => [],
                'pattern' => '#^/events(?:/|$)#',
                'handler' => EventsPatternSubmenu::class,
            ],
        ]);

        $this->app->instance('request', Request::create('/events/42', 'GET'));

        $items = app(ContextSubmenuResolver::class)->resolve();

        self::assertCount(1, $items);
        self::assertSame('Pattern', $items[0]['label']);
    }
}

final class ExactSubmenu implements MenuHandler
{
    public function items(): array
    {
        return [[
            'label' => 'Exact',
            'url' => '#exact',
            'active' => false,
            'visible' => true,
        ]];
    }
}

final class EventsPatternSubmenu implements MenuHandler
{
    public function items(): array
    {
        return [
            [
                'label' => 'Pattern',
                'url' => '#pattern',
                'active' => false,
                'visible' => true,
            ],
            [
                'label' => 'Hidden',
                'url' => '#hidden',
                'active' => false,
                'visible' => false,
            ],
        ];
    }
}
