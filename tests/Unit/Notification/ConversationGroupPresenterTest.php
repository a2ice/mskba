<?php

namespace Tests\Unit\Notification;

use App\Modules\Notification\Presentation\Presenters\ConversationGroupPresenter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ConversationGroupPresenterTest extends TestCase
{
    public function test_groups_keep_independent_threads_and_aggregate_unread_counts(): void
    {
        $presenter = new ConversationGroupPresenter();
        $cards = collect([
            ['id' => 'support-1', 'date' => CarbonImmutable::parse('2026-10-14 09:00:00'), 'unread_count' => 1],
            ['id' => 'direct-1', 'date' => CarbonImmutable::parse('2026-10-14 10:00:00'), 'unread_count' => 0],
            ['id' => 'support-2', 'date' => CarbonImmutable::parse('2026-10-14 11:00:00'), 'unread_count' => 2],
            ['id' => 11, 'date' => CarbonImmutable::parse('2026-10-14 08:00:00'), 'unread_count' => 0],
            ['id' => 'profile-5', 'date' => CarbonImmutable::parse('2026-10-14 12:00:00'), 'unread_count' => 3],
            ['id' => 'system', 'date' => CarbonImmutable::parse('2026-10-14 07:00:00'), 'unread_count' => 4],
        ]);

        $groups = $presenter->groups($cards);
        self::assertSame(['support', 'requests', 'direct', 'system'], array_column($groups, 'key'));
        self::assertSame([3, 3, 0, 4], array_column($groups, 'unread_count'));
        self::assertSame(['support-2', 'support-1'], $groups[0]['items']->pluck('id')->all());
        self::assertSame(['profile-5', 11], $groups[1]['items']->pluck('id')->all());
        self::assertSame(6, array_sum(array_map(fn (array $group): int => $group['items']->count(), $groups)));
    }
}
