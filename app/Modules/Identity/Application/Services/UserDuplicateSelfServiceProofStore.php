<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class UserDuplicateSelfServiceProofStore
{
    public function issue(
        UserDuplicate $candidate,
        User $actor,
        int $telegramUserId,
        string $sessionId,
    ): void {
        $actor = $actor->canonical();
        $this->assertProofContext($candidate, $actor, $telegramUserId, $sessionId);

        Cache::put(
            $this->key($candidate, $actor, $sessionId),
            [
                'candidate_id' => (int) $candidate->id,
                'actor_user_id' => (int) $actor->id,
                'telegram_user_id' => $telegramUserId,
                'issued_at' => now()->timestamp,
            ],
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    public function has(
        UserDuplicate $candidate,
        User $actor,
        string $sessionId,
    ): bool {
        $actor = $actor->canonical();

        if ($sessionId === '') {
            return false;
        }

        return $this->isValidProof(
            Cache::get($this->key($candidate, $actor, $sessionId)),
            $candidate,
            $actor,
            $sessionId,
        );
    }

    public function consume(
        UserDuplicate $candidate,
        User $actor,
        string $sessionId,
    ): bool {
        $actor = $actor->canonical();

        if ($sessionId === '') {
            return false;
        }

        return $this->isValidProof(
            Cache::pull($this->key($candidate, $actor, $sessionId)),
            $candidate,
            $actor,
            $sessionId,
        );
    }

    private function isValidProof(
        mixed $proof,
        UserDuplicate $candidate,
        User $actor,
        string $sessionId,
    ): bool {
        if (! is_array($proof)) {
            return false;
        }

        $telegramUserId = (int) ($proof['telegram_user_id'] ?? 0);
        $issuedAt = (int) ($proof['issued_at'] ?? 0);

        if (
            (int) ($proof['candidate_id'] ?? 0) !== (int) $candidate->id
            || (int) ($proof['actor_user_id'] ?? 0) !== (int) $actor->id
            || $telegramUserId <= 0
            || $issuedAt <= 0
            || $issuedAt < now()->subSeconds($this->ttlSeconds())->timestamp
            || $issuedAt > now()->addMinute()->timestamp
        ) {
            return false;
        }

        try {
            $this->assertProofContext($candidate, $actor, $telegramUserId, $sessionId);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function assertProofContext(
        UserDuplicate $candidate,
        User $actor,
        int $telegramUserId,
        string $sessionId,
    ): void {
        if ($sessionId === '') {
            throw new InvalidArgumentException('Сессия подтверждения аккаунта недоступна.');
        }

        if ($candidate->status !== UserDuplicateStatusEnum::PENDING) {
            throw new InvalidArgumentException('Объединить можно только активного кандидата на дублирование.');
        }

        $pairIds = [(int) $candidate->user_id, (int) $candidate->duplicate_user_id];
        if (! in_array((int) $actor->id, $pairIds, true)) {
            throw new InvalidArgumentException('Подтверждение не относится к текущему пользователю.');
        }

        $hasMatchingEvidence = $candidate->evidence()
            ->where('is_active', true)
            ->where('type', 'telegram_identity')
            ->get()
            ->contains(function ($evidence) use ($actor, $telegramUserId): bool {
                return (int) ($evidence->metadata['self_service_user_id'] ?? 0) === (int) $actor->id
                    && (int) ($evidence->metadata['telegram_user_id'] ?? 0) === $telegramUserId
                    && ($evidence->metadata['source'] ?? null) === 'signed_telegram_auth';
            });

        if (! $hasMatchingEvidence) {
            throw new InvalidArgumentException('Свежая Telegram-аутентификация не подтверждает эту пару аккаунтов.');
        }
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('telegram.user_duplicate_merge_proof_ttl', 600));
    }

    private function key(UserDuplicate $candidate, User $actor, string $sessionId): string
    {
        return sprintf(
            'identity:user-duplicate-merge-proof:%d:%d:%s',
            (int) $candidate->id,
            (int) $actor->id,
            hash('sha256', $sessionId),
        );
    }
}
