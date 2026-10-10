<?php

namespace Tests\Unit\Presentation\Navigation;

use App\Presentation\Navigation\AdaptiveMenuGroup;
use PHPUnit\Framework\TestCase;

final class AdaptiveMenuGroupTest extends TestCase
{
    public function test_zero_visible_links_have_no_group_or_link(): void
    {
        self::assertSame([], AdaptiveMenuGroup::wrap('Мой MSKBA', []));
        self::assertSame([], AdaptiveMenuGroup::wrap('Мой MSKBA', [
            ['label' => 'Скрытый раздел', 'url' => '/hidden', 'active' => false, 'visible' => false],
        ]));
    }

    public function test_single_visible_link_is_promoted_unchanged_without_a_group(): void
    {
        $link = [
            'label' => 'Мои площадки',
            'url' => '/account/venues',
            'active' => true,
            'visible' => true,
            'badge' => 3,
            'badgeAttribute' => 'data-venue-booking-request-count',
        ];
        self::assertSame(
            [[...$link, 'openMobileOnActive' => true]],
            AdaptiveMenuGroup::wrap('Мой MSKBA', [$link]),
        );
    }

    public function test_visibility_is_calculated_before_grouping(): void
    {
        $one = ['label' => 'Мои игры', 'url' => '/account/my-games', 'active' => false, 'visible' => true];
        $hidden = ['label' => 'Мои площадки', 'url' => '/account/venues', 'active' => true, 'visible' => false];
        self::assertSame([[...$one, 'openMobileOnActive' => true]], AdaptiveMenuGroup::wrap('Мой MSKBA', [$hidden, $one]));
    }

    public function test_two_or_more_links_remain_in_order_inside_group_with_active_child(): void
    {
        $first = ['label' => 'Мои команды', 'url' => '/account/teams', 'active' => false, 'visible' => true];
        $second = ['label' => 'Мои игры', 'url' => '/account/my-games', 'active' => true, 'visible' => true];
        self::assertSame([[
            'label' => 'Мой MSKBA',
            'url' => null,
            'active' => true,
            'visible' => true,
            'children' => [$first, $second],
        ]], AdaptiveMenuGroup::wrap('Мой MSKBA', [$first, $second]));
        self::assertFalse(AdaptiveMenuGroup::wrap('Мой MSKBA', [$first, [...$second, 'active' => false]])[0]['active']);
    }
}
