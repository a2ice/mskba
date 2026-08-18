<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Data\ReactionActor;
use App\Modules\Reaction\Application\Data\ReactionSummary;
use App\Modules\Reaction\Domain\Enums\ReactionActorTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;
use App\Modules\Reaction\Domain\Models\Reaction;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class ReactionService
{
    public function __construct(
        private ReactionActorResolver $actors,
        private ReactionReadService $read,
    ) {}

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setForUser(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        User $user,
        ?ReactionValueEnum $value,
        ReactionSourceEnum $source = ReactionSourceEnum::WEB,
        ?array $sourceMetadata = null,
    ): ReactionSummary {
        $actor = $this->actors->forUser($user);
        $sourceOccurredAt = now();

        $this->withActorLock($subjectType, $subjectId, $actor, function () use (
            $subjectType,
            $subjectId,
            $user,
            $actor,
            $value,
            $source,
            $sourceOccurredAt,
            $sourceMetadata,
        ): void {
            DB::transaction(function () use (
                $subjectType,
                $subjectId,
                $user,
                $actor,
                $value,
                $source,
                $sourceOccurredAt,
                $sourceMetadata,
            ): void {
                $telegramUserId = TelegramAccount::query()
                    ->where('user_id', $user->getKey())
                    ->value('telegram_user_id');

                if (is_numeric($telegramUserId)) {
                    $this->deleteActorReaction(
                        $subjectType,
                        $subjectId,
                        $this->actors->telegramActor((int) $telegramUserId),
                    );
                }

                $this->persist(
                    $subjectType,
                    $subjectId,
                    $actor,
                    $value,
                    $source,
                    $sourceOccurredAt,
                    null,
                    $sourceMetadata,
                );
            });
        });

        return $this->read->forSubject($subjectType, $subjectId, $user);
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setForTelegramUser(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        int $telegramUserId,
        ?ReactionValueEnum $value,
        CarbonInterface $sourceOccurredAt,
        ?int $sourceSequence = null,
        ?array $sourceMetadata = null,
    ): ReactionSummary {
        $actor = $this->actors->forTelegramUser($telegramUserId);

        $this->withActorLock($subjectType, $subjectId, $actor, function () use (
            $subjectType,
            $subjectId,
            $telegramUserId,
            $actor,
            $value,
            $sourceOccurredAt,
            $sourceSequence,
            $sourceMetadata,
        ): void {
            DB::transaction(function () use (
                $subjectType,
                $subjectId,
                $telegramUserId,
                $actor,
                $value,
                $sourceOccurredAt,
                $sourceSequence,
                $sourceMetadata,
            ): void {
                if ($actor->type === ReactionActorTypeEnum::USER) {
                    $this->deleteActorReaction(
                        $subjectType,
                        $subjectId,
                        $this->actors->telegramActor($telegramUserId),
                    );
                }

                $this->persist(
                    $subjectType,
                    $subjectId,
                    $actor,
                    $value,
                    ReactionSourceEnum::TELEGRAM,
                    $sourceOccurredAt,
                    $sourceSequence,
                    $sourceMetadata,
                );
            });
        });

        return $this->read->forSubject($subjectType, $subjectId);
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    private function persist(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionActor $actor,
        ?ReactionValueEnum $value,
        ReactionSourceEnum $source,
        CarbonInterface $sourceOccurredAt,
        ?int $sourceSequence,
        ?array $sourceMetadata,
    ): void {
        $current = Reaction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->lockForUpdate()
            ->first();

        if ($current !== null && ! $this->incomingWins($current, $source, $sourceOccurredAt, $sourceSequence)) {
            return;
        }

        $storedValue = $value === null || $value === ReactionValueEnum::NONE
            ? ReactionValueEnum::NONE
            : $value;
        $now = now();

        DB::table('reactions')->upsert([
            [
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
                'actor_type' => $actor->type->value,
                'actor_id' => $actor->id,
                'user_id' => $actor->userId,
                'value' => $storedValue->value,
                'source' => $source->value,
                'source_occurred_at' => $sourceOccurredAt,
                'source_sequence' => $sourceSequence,
                'source_metadata' => $sourceMetadata === null
                    ? null
                    : json_encode($sourceMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], [
            'subject_type',
            'subject_id',
            'actor_type',
            'actor_id',
        ], [
            'user_id',
            'value',
            'source',
            'source_occurred_at',
            'source_sequence',
            'source_metadata',
            'updated_at',
        ]);
    }

    private function incomingWins(
        Reaction $current,
        ReactionSourceEnum $incomingSource,
        CarbonInterface $incomingOccurredAt,
        ?int $incomingSequence,
    ): bool {
        if ($current->source_occurred_at === null) {
            return true;
        }

        if ($current->source_occurred_at->greaterThan($incomingOccurredAt)) {
            return false;
        }

        if (! $current->source_occurred_at->equalTo($incomingOccurredAt)) {
            return true;
        }

        if ($current->source === ReactionSourceEnum::WEB && $incomingSource === ReactionSourceEnum::TELEGRAM) {
            return false;
        }

        if (
            $current->source === ReactionSourceEnum::TELEGRAM
            && $incomingSource === ReactionSourceEnum::TELEGRAM
            && $current->source_sequence !== null
            && $incomingSequence !== null
        ) {
            return $incomingSequence > $current->source_sequence;
        }

        return true;
    }

    /** @param callable(): void $callback */
    private function withActorLock(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionActor $actor,
        callable $callback,
    ): void {
        $key = sprintf(
            'reaction:%s:%d:%s:%s',
            $subjectType->value,
            $subjectId,
            $actor->type->value,
            hash('sha256', $actor->id),
        );

        Cache::lock($key, 10)->block(3, $callback);
    }

    private function deleteActorReaction(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionActor $actor,
    ): void {
        Reaction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->delete();
    }
}
