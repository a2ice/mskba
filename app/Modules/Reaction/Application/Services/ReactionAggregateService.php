<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Models\ReactionAggregate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class ReactionAggregateService
{
    /** @param array<string, mixed>|null $sourceMetadata */
    public function set(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionSourceEnum $source,
        string $sourceKey,
        int $likes,
        int $dislikes,
        CarbonInterface $sourceOccurredAt,
        ?int $sourceSequence = null,
        ?array $sourceMetadata = null,
    ): void {
        $lockKey = $this->lockKey($subjectType, $subjectId, $source, $sourceKey);

        Cache::lock($lockKey, 10)->block(3, function () use (
            $subjectType,
            $subjectId,
            $source,
            $sourceKey,
            $likes,
            $dislikes,
            $sourceOccurredAt,
            $sourceSequence,
            $sourceMetadata,
        ): void {
            DB::transaction(function () use (
                $subjectType,
                $subjectId,
                $source,
                $sourceKey,
                $likes,
                $dislikes,
                $sourceOccurredAt,
                $sourceSequence,
                $sourceMetadata,
            ): void {
                $current = ReactionAggregate::query()
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subjectId)
                    ->where('source', $source)
                    ->where('source_key', $sourceKey)
                    ->lockForUpdate()
                    ->first();

                if ($current !== null && ! $this->incomingWins($current, $sourceOccurredAt, $sourceSequence)) {
                    return;
                }

                ReactionAggregate::query()->updateOrCreate([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'source' => $source,
                    'source_key' => $sourceKey,
                ], [
                    'likes_count' => max(0, $likes),
                    'dislikes_count' => max(0, $dislikes),
                    'source_occurred_at' => $sourceOccurredAt,
                    'source_sequence' => $sourceSequence,
                    'source_metadata' => $sourceMetadata,
                ]);
            });
        });
    }

    public function clear(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionSourceEnum $source,
        string $sourceKey,
    ): void {
        Cache::lock($this->lockKey($subjectType, $subjectId, $source, $sourceKey), 10)
            ->block(3, fn () => ReactionAggregate::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('source', $source)
                ->where('source_key', $sourceKey)
                ->delete());
    }

    private function lockKey(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ReactionSourceEnum $source,
        string $sourceKey,
    ): string {
        return sprintf(
            'reaction-aggregate:%s:%d:%s:%s',
            $subjectType->value,
            $subjectId,
            $source->value,
            hash('sha256', $sourceKey),
        );
    }

    private function incomingWins(
        ReactionAggregate $current,
        CarbonInterface $incomingOccurredAt,
        ?int $incomingSequence,
    ): bool {
        if ($current->source_sequence !== null && $incomingSequence !== null) {
            return $incomingSequence > $current->source_sequence;
        }

        return $current->source_occurred_at === null
            || ! $current->source_occurred_at->greaterThan($incomingOccurredAt);
    }
}
