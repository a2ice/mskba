<?php

namespace App\Modules\Portal\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lightweight, deliberately ephemeral metadata: no IP, query strings, message
 * bodies, cookies, session tokens or database rows. This is advisory activity,
 * never an authorization signal. Uses Redis in production.
 */
final class OperatorActivityService
{
    private const HISTORY_LIMIT = 100;

    private const HISTORY_TTL = 7200;

    public function record(User $actor, string $sessionId, string $pathname): void
    {
        $actor = $actor->canonical();
        if ($actor->isBlocked() || $actor->trashed() || $sessionId === '') {
            return;
        }

        $page = $this->safePath($pathname);
        $session = hash('sha256', $sessionId);
        $userId = (int) $actor->id;
        $store = Cache::store((string) config('operator_activity.cache_store', 'redis'));

        try {
            $store->lock('operator-activity:lock:'.$userId, 3)->block(1, function () use ($store, $userId, $session, $page): void {
                $now = now()->timestamp;
                $stateKey = 'operator-activity:current:'.$userId;
                $historyKey = 'operator-activity:history:'.$userId.':'.$session;
                $history = $store->get($historyKey, []);
                $history = is_array($history) ? $history : [];
                $last = end($history);

                if (! is_array($last) || ($last['path'] ?? null) !== $page) {
                    $history[] = ['path' => $page, 'at' => $now];
                    $history = array_slice($history, -self::HISTORY_LIMIT);
                }
                $store->put($historyKey, $history, self::HISTORY_TTL);
                $store->put($stateKey, [
                    'session' => $session,
                    'path' => $page,
                    'at' => $now,
                ], self::HISTORY_TTL);
            });
        } catch (Throwable $exception) {
            // Presence must never block site navigation if Redis is unavailable.
            report($exception);
        }
    }

    /** @return array{path: string, at: int, online: bool}|null */
    public function state(User $user): ?array
    {
        $data = Cache::store((string) config('operator_activity.cache_store', 'redis'))
            ->get('operator-activity:current:'.$user->canonical()->id);

        if (! is_array($data) || ! is_string($data['path'] ?? null) || ! is_numeric($data['at'] ?? null)) {
            return null;
        }

        $timestamp = (int) $data['at'];

        return [
            'path' => $data['path'],
            'at' => $timestamp,
            'online' => $timestamp >= now()->timestamp - (int) config('operator_activity.online_seconds', 150),
        ];
    }

    /** @return list<array{path: string, at: int}> */
    public function history(User $user): array
    {
        $userId = $user->canonical()->id;
        $store = Cache::store((string) config('operator_activity.cache_store', 'redis'));
        $current = $store->get('operator-activity:current:'.$userId);
        if (! is_array($current) || ! is_string($current['session'] ?? null)) {
            return [];
        }

        $history = $store->get('operator-activity:history:'.$userId.':'.$current['session'], []);

        return is_array($history) ? array_slice($history, -self::HISTORY_LIMIT) : [];
    }

    public function safePath(string $pathname): string
    {
        // Only a path from our own site, never query strings, fragments or
        // potentially sensitive account/admin URL segments.
        $path = parse_url($pathname, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/') || strlen($path) > 190
            || str_starts_with($pathname, '//') || str_contains($path, '%')
            || preg_match('~[^/a-zA-Z0-9_\\-]~', $path)) {
            return '/';
        }

        if (preg_match('~^/(account|backoffice|admin|api|auth|login|logout|integrations)(/|$)~i', $path, $matches)) {
            return '/'.$matches[1];
        }

        return $path;
    }
}
