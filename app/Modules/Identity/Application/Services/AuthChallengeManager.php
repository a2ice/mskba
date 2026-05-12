<?php

namespace App\Modules\Identity\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

class AuthChallengeManager
{
    private const CHALLENGE_TTL_SECONDS = 600;

    public function issue(Session $session, int $userId, string $flow): string
    {
        $challengeKey = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->timestamp;

        $session->put('auth_challenges.' . $challengeKey, [
            'user_id' => $userId,
            'flow' => $flow,
            'expires_at' => $expiresAt,
        ]);

        return $challengeKey;
    }

    /**
     * @return array{user_id:int,flow:string,expires_at:int}|null
     */
    public function resolve(Session $session, string $challengeKey): ?array
    {
        $challenge = $session->get('auth_challenges.' . $challengeKey);
        if (! is_array($challenge)) {
            return null;
        }

        $expiresAt = (int) ($challenge['expires_at'] ?? 0);
        if ($expiresAt < CarbonImmutable::now()->timestamp) {
            $session->forget('auth_challenges.' . $challengeKey);
            return null;
        }

        if (! isset($challenge['user_id'], $challenge['flow'])) {
            return null;
        }

        return [
            'user_id' => (int) $challenge['user_id'],
            'flow' => (string) $challenge['flow'],
            'expires_at' => $expiresAt,
        ];
    }

    public function consume(Session $session, string $challengeKey): void
    {
        $session->forget('auth_challenges.' . $challengeKey);
    }
}
