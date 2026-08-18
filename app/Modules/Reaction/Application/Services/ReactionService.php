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

        DB::transaction(function () use ($subjectType, $subjectId, $user, $actor, $value, $source, $sourceMetadata): void {
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

            $this->persist($subjectType, $subjectId, $actor, $value, $source, $sourceMetadata);
        });

        return $this->read->forSubject($subjectType, $subjectId, $user);
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setForTelegramUser(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        int $telegramUserId,
        ?ReactionValueEnum $value,
        ?array $sourceMetadata = null,
    ): ReactionSummary {
        $actor = $this->actors->forTelegramUser($telegramUserId);

        DB::transaction(function () use ($subjectType, $subjectId, $telegramUserId, $actor, $value, $sourceMetadata): void {
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
                $sourceMetadata,
            );
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
        ?array $sourceMetadata,
    ): void {
        if ($value === null) {
            $this->deleteActorReaction($subjectType, $subjectId, $actor);

            return;
        }

        $now = now();
        DB::table('reactions')->upsert([
            [
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
                'actor_type' => $actor->type->value,
                'actor_id' => $actor->id,
                'user_id' => $actor->userId,
                'value' => $value->value,
                'source' => $source->value,
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
            'source_metadata',
            'updated_at',
        ]);
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
