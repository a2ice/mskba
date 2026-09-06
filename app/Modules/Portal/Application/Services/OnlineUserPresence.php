<?php

namespace App\Modules\Portal\Application\Services;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OnlineUserPresence
{
    private const USER_CACHE_KEY = 'site-summary:online-presence';

    private const VISITOR_CACHE_KEY = 'site-summary:online-visitors';

    public function touch(int $userId): void
    {
        $this->touchPresence(self::USER_CACHE_KEY, $userId);
    }

    public function touchVisitor(int $fingerprintId): void
    {
        $this->touchPresence(self::VISITOR_CACHE_KEY, $fingerprintId);
    }

    public function forget(int $userId): void
    {
        try {
            if ($this->usesRedis()) {
                Redis::connection($this->redisConnection())->zrem(self::USER_CACHE_KEY, (string) $userId);

                return;
            }

            $this->updateCachePresence(self::USER_CACHE_KEY, null, $userId);
        } catch (Throwable) {
            // Presence must never make a page or logout unavailable.
        }
    }

    public function count(): int
    {
        return $this->countPresence(self::USER_CACHE_KEY);
    }

    public function visitorCount(): int
    {
        return $this->countPresence(self::VISITOR_CACHE_KEY);
    }

    /** @return array<int, int> User ID indexed timestamps of the last activity. */
    public function snapshot(): array
    {
        return $this->snapshotPresence(self::USER_CACHE_KEY);
    }

    private function touchPresence(string $key, int $memberId): void
    {
        try {
            if ($this->usesRedis()) {
                $this->touchRedis($key, $memberId);

                return;
            }

            $this->updateCachePresence($key, $memberId);
        } catch (Throwable) {
            // Presence must never make a page unavailable.
        }
    }

    private function countPresence(string $key): int
    {
        try {
            if ($this->usesRedis()) {
                $redis = Redis::connection($this->redisConnection());
                $redis->zremrangebyscore($key, '-inf', (string) $this->expirationTimestamp());

                return (int) $redis->zcard($key);
            }

            return count($this->updateCachePresence($key));
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int, int> Member ID indexed timestamps of the last activity. */
    private function snapshotPresence(string $key): array
    {
        try {
            if ($this->usesRedis()) {
                $redis = Redis::connection($this->redisConnection());
                $redis->zremrangebyscore($key, '-inf', (string) $this->expirationTimestamp());
                $members = $redis->zrangebyscore(
                    $key,
                    (string) ($this->expirationTimestamp() + 1),
                    '+inf',
                    ['withscores' => true],
                );

                if (! is_array($members)) {
                    return [];
                }

                $snapshot = [];

                foreach ($members as $memberId => $timestamp) {
                    if (is_numeric($memberId) && is_numeric($timestamp)) {
                        $snapshot[(int) $memberId] = (int) $timestamp;
                    }
                }

                return $snapshot;
            }

            return $this->updateCachePresence($key);
        } catch (Throwable) {
            return [];
        }
    }

    private function touchRedis(string $key, int $memberId): void
    {
        $redis = Redis::connection($this->redisConnection());
        $redis->zadd($key, now()->timestamp, (string) $memberId);
        $redis->zremrangebyscore($key, '-inf', (string) $this->expirationTimestamp());
        $redis->expire($key, $this->windowSeconds() * 2);
    }

    /** @return array<int, int> */
    private function updateCachePresence(
        string $key,
        ?int $touchMemberId = null,
        ?int $forgetMemberId = null,
    ): array {
        $repository = $this->cacheRepository();
        $presence = $repository->get($key, []);
        $presence = is_array($presence) ? $presence : [];
        $expiresBefore = $this->expirationTimestamp();

        $presence = array_filter(
            $presence,
            static fn (mixed $timestamp): bool => is_numeric($timestamp) && (int) $timestamp > $expiresBefore,
        );

        if ($touchMemberId !== null) {
            $presence[(string) $touchMemberId] = now()->timestamp;
        }

        if ($forgetMemberId !== null) {
            unset($presence[(string) $forgetMemberId]);
        }

        $repository->put($key, $presence, $this->windowSeconds() * 2);

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
