<?php

namespace App\Modules\Telegram\Application\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class TelegramBotLoginChallengeStore
{
    private const KEY_PREFIX = 'telegram:bot-login:';

    /**
     * @return array{token: string, expires_at: int}
     */
    public function create(string $browserKey, string $redirectUrl): array
    {
        $token = Str::of(base64_encode(random_bytes(32)))
            ->replace(['+', '/', '='], ['-', '_', ''])
            ->toString();
        $expiresAt = now()->addSeconds($this->ttl())->getTimestamp();

        Cache::put($this->key($token), [
            'status' => 'pending',
            'browser_session_hash' => hash('sha256', $browserKey),
            'redirect_url' => $redirectUrl,
            'expires_at' => $expiresAt,
            'user_id' => null,
            'telegram_account_id' => null,
            'created' => false,
        ], $this->ttl());

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $token): ?array
    {
        if (! $this->validToken($token)) {
            return null;
        }

        $challenge = Cache::get($this->key($token));

        if (! is_array($challenge) || (int) ($challenge['expires_at'] ?? 0) <= now()->getTimestamp()) {
            Cache::forget($this->key($token));

            return null;
        }

        return $challenge;
    }

    public function approve(
        string $token,
        int $userId,
        int $telegramAccountId,
        bool $created,
    ): bool {
        if (! $this->validToken($token)) {
            return false;
        }

        return Cache::lock($this->lockKey($token), 10)->block(3, function () use (
            $token,
            $userId,
            $telegramAccountId,
            $created,
        ): bool {
            $challenge = $this->find($token);

            if ($challenge === null || ($challenge['status'] ?? null) !== 'pending') {
                return false;
            }

            $challenge['status'] = 'approved';
            $challenge['user_id'] = $userId;
            $challenge['telegram_account_id'] = $telegramAccountId;
            $challenge['created'] = $created;

            Cache::put(
                $this->key($token),
                $challenge,
                max(1, (int) $challenge['expires_at'] - now()->getTimestamp()),
            );

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $consume
     * @return array{status: 'pending'}|array{status: 'expired'}|array{status: 'success', result: T}
     */
    public function consumeForBrowser(
        string $token,
        string $browserKey,
        Closure $consume,
    ): array {
        if (! $this->validToken($token)) {
            return ['status' => 'expired'];
        }

        return Cache::lock($this->lockKey($token), 10)->block(3, function () use (
            $token,
            $browserKey,
            $consume,
        ): array {
            $challenge = $this->find($token);

            if ($challenge === null
                || ! hash_equals(
                    (string) ($challenge['browser_session_hash'] ?? ''),
                    hash('sha256', $browserKey),
                )) {
                return ['status' => 'expired'];
            }

            if (($challenge['status'] ?? null) !== 'approved') {
                return ['status' => 'pending'];
            }

            $result = $consume($challenge);
            Cache::forget($this->key($token));

            return [
                'status' => 'success',
                'result' => $result,
            ];
        });
    }

    private function ttl(): int
    {
        return max(60, (int) config('telegram.bot_login_ttl', 300));
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    private function key(string $token): string
    {
        return self::KEY_PREFIX.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return $this->key($token).':lock';
    }
}
