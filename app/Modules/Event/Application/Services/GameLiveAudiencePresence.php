<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Application\DTO\GameLiveAudienceDTO;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class GameLiveAudiencePresence
{
    public function touch(int $gameId, string $viewerId, bool $authenticated): GameLiveAudienceDTO
    {
        try {
            if ($this->usesRedis()) {
                return $this->touchRedis($gameId, $viewerId, $authenticated);
            }

            return $this->touchCache($gameId, $viewerId, $authenticated);
        } catch (Throwable) {
            return new GameLiveAudienceDTO(0, 0);
        }
    }

    private function touchRedis(int $gameId, string $viewerId, bool $authenticated): GameLiveAudienceDTO
    {
        $redis = Redis::connection($this->redisConnection());
        $totalKey = $this->redisKey($gameId, 'total');
        $authenticatedKey = $this->redisKey($gameId, 'authenticated');
        $now = now()->timestamp;
        $expiredAt = $this->expirationTimestamp();

        $redis->zadd($totalKey, $now, $viewerId);
        $authenticated
            ? $redis->zadd($authenticatedKey, $now, $viewerId)
            : $redis->zrem($authenticatedKey, $viewerId);

        foreach ([$totalKey, $authenticatedKey] as $key) {
            $redis->zremrangebyscore($key, '-inf', (string) $expiredAt);
            $redis->expire($key, $this->windowSeconds() * 2);
        }

        return new GameLiveAudienceDTO(
            authenticated: (int) $redis->zcard($authenticatedKey),
            total: (int) $redis->zcard($totalKey),
        );
    }

    private function touchCache(int $gameId, string $viewerId, bool $authenticated): GameLiveAudienceDTO
    {
        $repository = $this->cacheRepository();
        $key = 'game-live:audience:'.$gameId;

        return $repository->lock($key.':lock', 5)->block(2, function () use ($repository, $key, $viewerId, $authenticated): GameLiveAudienceDTO {
            $presence = $repository->get($key, []);
            $presence = is_array($presence) ? $presence : [];
            $expiredAt = $this->expirationTimestamp();

            $presence = array_filter(
                $presence,
                static fn (mixed $viewer): bool => is_array($viewer)
                    && is_numeric($viewer['seen_at'] ?? null)
                    && (int) $viewer['seen_at'] > $expiredAt,
            );
            $presence[$viewerId] = [
                'seen_at' => now()->timestamp,
                'authenticated' => $authenticated,
            ];

            $repository->put($key, $presence, $this->windowSeconds() * 2);

            return new GameLiveAudienceDTO(
                authenticated: count(array_filter($presence, static fn (array $viewer): bool => (bool) ($viewer['authenticated'] ?? false))),
                total: count($presence),
            );
        });
    }

    private function usesRedis(): bool
    {
        return config('game_live.presence_store') === 'redis';
    }

    private function redisConnection(): string
    {
        return (string) config('game_live.presence_redis_connection', 'cache');
    }

    private function windowSeconds(): int
    {
        return max(30, (int) config('game_live.presence_window_seconds', 120));
    }

    private function expirationTimestamp(): int
    {
        return now()->timestamp - $this->windowSeconds();
    }

    private function redisKey(int $gameId, string $audience): string
    {
        return sprintf('game-live:%d:audience:%s', $gameId, $audience);
    }

    private function cacheRepository(): Repository
    {
        $store = config('game_live.presence_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
