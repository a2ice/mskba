<?php

namespace App\Modules\Portal\Application\Services;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OnlineUserPresence
{
    private const CACHE_KEY = 'site-summary:online-presence';

    public function touch(int $userId): void
    {
        if ($this->usesRedis()) {
            $this->touchRedis($userId);

            return;
        }

        $this->updateCachePresence($userId);
    }

    public function forget(int $userId): void
    {
        try {
            if ($this->usesRedis()) {
                Redis::connection($this->redisConnection())->zrem(self::CACHE_KEY, (string) $userId);

                return;
            }

            $this->updateCachePresence(null, $userId);
        } catch (Throwable) {
            // Presence must never make a page or logout unavailable.
        }
    }

    public function count(): int
    {
        try {
            if ($this->usesRedis()) {
                $redis = Redis::connection($this->redisConnection());
                $redis->zremrangebyscore(self::CACHE_KEY, '-inf', (string) $this->expirationTimestamp());

                return (int) $redis->zcard(self::CACHE_KEY);
            }

            return count($this->updateCachePresence());
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int, int> User ID indexed timestamps of the last activity. */
    public function snapshot(): array
    {
        try {
            if ($this->usesRedis()) {
                $redis = Redis::connection($this->redisConnection());
                $redis->zremrangebyscore(self::CACHE_KEY, '-inf', (string) $this->expirationTimestamp());
                $members = $redis->zrangebyscore(
                    self::CACHE_KEY,
                    (string) ($this->expirationTimestamp() + 1),
                    '+inf',
                    ['withscores' => true],
                );

                if (! is_array($members)) {
                    return [];
                }

                $snapshot = [];

                foreach ($members as $userId => $timestamp) {
                    if (is_numeric($userId) && is_numeric($timestamp)) {
                        $snapshot[(int) $userId] = (int) $timestamp;
                    }
                }

                return $snapshot;
            }

            return $this->updateCachePresence();
        } catch (Throwable) {
            return [];
        }
    }

    private function touchRedis(int $userId): void
    {
        try {
            $redis = Redis::connection($this->redisConnection());
            $redis->zadd(self::CACHE_KEY, now()->timestamp, (string) $userId);
            $redis->zremrangebyscore(self::CACHE_KEY, '-inf', (string) $this->expirationTimestamp());
            $redis->expire(self::CACHE_KEY, $this->windowSeconds() * 2);
        } catch (Throwable) {
            // The site remains usable if Redis is temporarily unavailable.
        }
    }

    /** @return array<int, int> */
    private function updateCachePresence(?int $touchUserId = null, ?int $forgetUserId = null): array
    {
        $repository = $this->cacheRepository();
        $presence = $repository->get(self::CACHE_KEY, []);
        $presence = is_array($presence) ? $presence : [];
        $expiresBefore = $this->expirationTimestamp();

        $presence = array_filter(
            $presence,
            static fn (mixed $timestamp): bool => is_numeric($timestamp) && (int) $timestamp > $expiresBefore,
        );

        if ($touchUserId !== null) {
            $presence[(string) $touchUserId] = now()->timestamp;
        }

        if ($forgetUserId !== null) {
            unset($presence[(string) $forgetUserId]);
        }

        $repository->put(self::CACHE_KEY, $presence, $this->windowSeconds() * 2);

        return array_map(
            static fn (mixed $timestamp): int => (int) $timestamp,
            $presence,
        );
    }

    private function usesRedis(): bool
    {
        return config('site_summary.presence_store') === 'redis';
    }

    private function redisConnection(): string
    {
        return (string) config('site_summary.presence_redis_connection', 'cache');
    }

    private function windowSeconds(): int
    {
        return max(30, (int) config('site_summary.presence_window_seconds', 120));
    }

    private function expirationTimestamp(): int
    {
        return now()->timestamp - $this->windowSeconds();
    }

    private function cacheRepository(): Repository
    {
        $store = config('site_summary.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
