<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Data\ReactionSummary;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;
use App\Modules\Reaction\Domain\Models\Reaction;
use Illuminate\Support\Facades\DB;

final readonly class ReactionReadService
{
    public function __construct(private ReactionActorResolver $actors) {}

    public function forSubject(
        ReactionSubjectTypeEnum $subjectType,
        int $subjectId,
        ?User $viewer = null,
    ): ReactionSummary {
        return $this->forSubjects($subjectType, [$subjectId], $viewer)[$subjectId];
    }

    /**
     * @param list<int> $subjectIds
     * @return array<int, ReactionSummary>
     */
    public function forSubjects(
        ReactionSubjectTypeEnum $subjectType,
        array $subjectIds,
        ?User $viewer = null,
    ): array {
        $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));

        if ($subjectIds === []) {
            return [];
        }

        /** @var array<int, array{likes: int, dislikes: int, viewer: ReactionValueEnum|null}> $data */
        $data = [];
        foreach ($subjectIds as $subjectId) {
            $data[$subjectId] = ['likes' => 0, 'dislikes' => 0, 'viewer' => null];
        }

        $counts = DB::table('reactions')
            ->selectRaw('subject_id, value, COUNT(*) as aggregate')
            ->where('subject_type', $subjectType->value)
            ->whereIn('subject_id', $subjectIds)
            ->groupBy('subject_id', 'value')
            ->get();

        foreach ($counts as $count) {
            $subjectId = (int) $count->subject_id;
            $value = (int) $count->value;
            $aggregate = (int) $count->aggregate;

            if (! isset($data[$subjectId])) {
                continue;
            }

            if ($value === ReactionValueEnum::LIKE->value) {
                $data[$subjectId]['likes'] = $aggregate;
            } elseif ($value === ReactionValueEnum::DISLIKE->value) {
                $data[$subjectId]['dislikes'] = $aggregate;
            }
        }

        if ($viewer !== null) {
            $actor = $this->actors->forUser($viewer);
            $viewerReactions = Reaction::query()
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $subjectIds)
                ->where('actor_type', $actor->type)
                ->where('actor_id', $actor->id)
                ->get(['subject_id', 'value']);

            foreach ($viewerReactions as $reaction) {
                $subjectId = (int) $reaction->subject_id;
                if (isset($data[$subjectId])) {
                    $data[$subjectId]['viewer'] = $reaction->value === ReactionValueEnum::NONE
                        ? null
                        : $reaction->value;
                }
            }
        }

        $summaries = [];
        foreach ($data as $subjectId => $summary) {
            $summaries[$subjectId] = new ReactionSummary(
                $summary['likes'],
                $summary['dislikes'],
                $summary['viewer'],
            );
        }

        return $summaries;
    }
}
