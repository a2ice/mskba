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

    public function test_specific_page_overrides_parent_and_other_paths_fall_back(): void
    {
        config()->set('submenu.sections', [
            'parent' => ['pattern' => '#^/venues(?:/|$)#', 'handler' => EventsPatternSubmenu::class],
            'child' => ['pattern' => '#^/venues/[^/]+$#', 'parent' => 'parent', 'handler' => ExactSubmenu::class],
        ]);
        $this->app->instance('request', Request::create('/venues/test-court'));
        self::assertSame(['Exact'], array_column(app(ContextSubmenuResolver::class)->resolve(), 'label'));
        $this->app->instance('request', Request::create('/venues/test-court/photos'));
        self::assertSame(['Pattern'], array_column(app(ContextSubmenuResolver::class)->resolve(), 'label'));
    }

    public function test_child_may_explicitly_include_parent_items(): void
    {
        config()->set('submenu.sections', [
            'parent' => ['pattern' => '#^/venues(?:/|$)#', 'handler' => EventsPatternSubmenu::class],
            'child' => ['pattern' => '#^/venues/[^/]+$#', 'parent' => 'parent', 'include_parent' => true, 'handler' => ExactSubmenu::class],
        ]);
        $this->app->instance('request', Request::create('/venues/test-court'));
        self::assertSame(['Exact', 'Pattern'], array_column(app(ContextSubmenuResolver::class)->resolve(), 'label'));
    }

    public function test_actual_venue_handlers_expose_expected_placeholder_actions(): void
    {
        $this->app->instance('request', Request::create('/venues'));
        self::assertSame(['Создать', 'Найти'], array_column(app(ContextSubmenuResolver::class)->resolve(), 'label'));

        // The child route constraint is important: /venues/create must not be
        // interpreted as a venue details page, despite matching the URL regex.
        $request = Request::create('/venues/test-court');
        $route = new \Illuminate\Routing\Route('GET', '/venues/{alias}', fn () => null);
        $route->name('venues.show');
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        self::assertSame(['Забронировать', 'Найти похожие'], array_column(app(ContextSubmenuResolver::class)->resolve(), 'label'));
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
