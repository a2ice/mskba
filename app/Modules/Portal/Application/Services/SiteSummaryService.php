<?php

namespace App\Modules\Portal\Application\Services;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\DTO\SiteSummaryDTO;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SiteSummaryService
{
    public function __construct(private readonly OnlineUserPresence $presence) {}

    public function get(): SiteSummaryDTO
    {
        return new SiteSummaryDTO(
            todayEvents: $this->todayEventsCount(),
            onlineUsers: $this->presence->count(),
            onlineVisitors: $this->presence->visitorCount(),
            totalUsers: $this->totalUsersCount(),
        );
    }

    public function forgetTodayEvents(): void
    {
        try {
            $this->repository()->forget($this->todayEventsCacheKey());
        } catch (Throwable) {
            // The database fallback will be used on the next request.
        }
    }

    public function forgetTotalUsers(): void
    {
        try {
            $this->repository()->forget($this->totalUsersCacheKey());
        } catch (Throwable) {
            // The database fallback will be used on the next request.
        }
    }

    private function todayEventsCount(): int
    {
        return $this->remember(
            $this->todayEventsCacheKey(),
            fn (): int => $this->queryTodayEventsCount(),
        );
    }

    private function totalUsersCount(): int
    {
        return $this->remember(
            $this->totalUsersCacheKey(),
            fn (): int => User::query()
                ->whereIn('status', [
                    UserStatusEnum::UNCONFIRMED->value,
                    UserStatusEnum::CONFIRMED->value,
                ])
                ->count(),
        );
    }

    private function queryTodayEventsCount(): int
    {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $day = now($timezone);

        return Event::query()
            ->whereIn('type', [
                EventTypeEnum::GAME->value,
                EventTypeEnum::GAME_TRAINING->value,
            ])
            ->whereIn('status', [
                EventStatusEnum::PUBLISHED->value,
                EventStatusEnum::COMPLETED->value,
            ])
            ->whereBetween('starts_at', [
                $day->copy()->startOfDay()->utc(),
                $day->copy()->endOfDay()->utc(),
            ])
            ->count();
    }

    private function remember(string $key, callable $resolver): int
    {
        try {
            return (int) $this->repository()->remember(
                $key,
                max(30, (int) config('site_summary.cache_ttl_seconds', 300)),
                $resolver,
            );
        } catch (Throwable) {
            try {
                return (int) $resolver();
            } catch (Throwable) {
                return 0;
            }
        }
    }

    private function todayEventsCacheKey(): string
    {
        return 'site-summary:today-events:'.now((string) config('app.timezone'))->toDateString();
    }

    private function totalUsersCacheKey(): string
    {
        return 'site-summary:total-users';
    }

    private function repository(): Repository
    {
        $store = config('site_summary.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
