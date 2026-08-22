<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class StandaloneGameInitialSelectionService
{
    public function __construct(
        private readonly TeamManagementAccess $teamAccess,
        private readonly GameAdmissionService $admissions,
        private readonly StandaloneGameFormationService $formation,
    ) {}

    public function apply(Game $game, Actor $actor, ?int $teamAId, ?int $teamBId): Game
    {
        $selectedIds = collect([$teamAId, $teamBId])
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($selectedIds->isEmpty()) {
            return $game;
        }
        if ($selectedIds->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Одна команда не может занимать обе стороны игры.');
        }

        $teams = $this->teams($selectedIds);
        $acceptedTeamIds = collect();

        foreach ($selectedIds as $teamId) {
            $team = $teams->get($teamId);
            if (! $team instanceof Team) {
                throw new InvalidArgumentException('Выбранная команда недоступна или больше не активна.');
            }

            if ($this->teamAccess->allows($team, $actor, TeamPermissionEnum::MANAGE_GAME_PARTICIPATION)) {
                $game->admissions()->create([
                    'candidate_type' => GameAdmissionCandidateTypeEnum::TEAM,
                    'team_id' => $team->id,
                    'direction' => GameAdmissionDirectionEnum::SELECTION,
                    'status' => GameAdmissionStatusEnum::ACCEPTED,
                    'requested_by_actor_id' => $actor->id,
                    'responded_by_actor_id' => $actor->id,
                    'responded_at' => now(),
                ]);
                $acceptedTeamIds->push($team->id);

                continue;
            }

            if (! $team->acceptsCompetitionInvitations()) {
                throw new InvalidArgumentException('Команда запретила приглашения в игры и турниры.');
            }

            $this->admissions->invite($game, $actor, $team);
        }

        if ($teamAId !== null
            && $teamBId !== null
            && $acceptedTeamIds->unique()->count() === 2) {
            return $this->formation->confirmTeams($game, $actor, (int) $teamAId, (int) $teamBId);
        }

        return $game->fresh(['admissions', 'sides', 'rosterEntries']);
    }

    /** @param Collection<int, int> $ids @return Collection<int, Team> */
    private function teams(Collection $ids): Collection
    {
        $teams = Team::query()
            ->whereIn('id', $ids)
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($teams->count() !== $ids->unique()->count()) {
            throw new InvalidArgumentException('Выбранная команда недоступна или больше не активна.');
        }

        return $teams;
    }
}
