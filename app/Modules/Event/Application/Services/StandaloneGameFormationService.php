<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Support\Basketball\BalancedTeamFormationEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StandaloneGameFormationService
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly BalancedTeamFormationEngine $balanced,
    ) {}

    public function initialize(
        Event $event,
        Actor $actor,
        ?int $teamAId,
        ?int $teamBId,
        int $sideASize = 5,
        int $sideBSize = 5,
        GameScoringTypeEnum $scoringType = GameScoringTypeEnum::STREETBALL,
        ?GameFormatEnum $format = null,
        GameTimingModeEnum $timingMode = GameTimingModeEnum::WHOLE_GAME,
        ?int $periodsCount = null,
        GameRecruitmentModeEnum $recruitmentMode = GameRecruitmentModeEnum::PREFORMED_TEAMS,
        bool $acceptsApplications = true,
    ): Game {
        if ($event->type !== EventTypeEnum::GAME) {
            throw new InvalidArgumentException('Формирование сторон доступно только для самостоятельной игры.');
        }

        $this->assertSideSizes($sideASize, $sideBSize);
        $this->assertRecruitmentFormat($recruitmentMode, $sideASize, $sideBSize);
        [$format, $periodsCount] = $this->normalizeFormat(
            $format,
            $sideASize,
            $sideBSize,
            $scoringType,
            $periodsCount,
        );
        $periodsCount = $this->normalizePeriods($timingMode, $periodsCount);

        $selectedIds = collect([$teamAId, $teamBId])
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->values();
        if ($recruitmentMode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT && $selectedIds->isNotEmpty()) {
            throw new InvalidArgumentException('В режиме набора отдельных игроков готовые команды при создании не указываются.');
        }
        if ($selectedIds->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Одна команда не может занимать обе стороны игры.');
        }

        $teams = $this->lockTeams($selectedIds);
        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $actor->id,
            'status' => GameStatusEnum::SCHEDULED,
            'recruitment_mode' => $recruitmentMode,
            'accepts_applications' => $acceptsApplications,
            'format' => $format,
            'timing_mode' => $timingMode,
            'side_a_size' => $sideASize,
            'side_b_size' => $sideBSize,
            'scoring_type' => $scoringType,
            'periods_count' => $periodsCount,
        ]);

        foreach ($selectedIds as $teamId) {
            $game->admissions()->create([
                'candidate_type' => GameAdmissionCandidateTypeEnum::TEAM,
                'team_id' => $teamId,
                'direction' => GameAdmissionDirectionEnum::SELECTION,
                'status' => GameAdmissionStatusEnum::ACCEPTED,
                'requested_by_actor_id' => $actor->id,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
            ]);
        }

        if ($teamAId !== null && $teamBId !== null) {
            $this->materializeTeams(
                $event,
                $game,
                $teams[(int) $teamAId],
                $teams[(int) $teamBId],
                $actor,
            );
        }
        $this->createPeriods($game, $periodsCount);
        $event->forceFill(['primary_game_id' => $game->id])->save();

        return $game->fresh(['sides', 'rosterEntries', 'admissions']);
    }

    /** @return array<string,mixed> */
    public function previewBalanced(Game $game, Actor $actor, string $assessmentSource, int $seed): array
    {
        $fresh = Game::query()->with('event')->findOrFail($game->id);
        $this->access->assertAllows(
            $fresh->event,
            $actor,
            EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
        );
        $this->assertIndividualFormationOpen($fresh);
        $users = $this->acceptedIndividualUsers($fresh);
        $minimum = (int) $fresh->side_a_size + (int) $fresh->side_b_size;
        if ($users->count() < $minimum) {
            throw new InvalidArgumentException("Для формирования двух сторон нужно не меньше {$minimum} принятых игроков.");
        }

        return [
            ...$this->balanced->build($users, $assessmentSource, 2, $seed),
            'pool_fingerprint' => $this->poolFingerprint($fresh),
        ];
    }

    /** @param list<array{number:int,name:string,logo_preset:string,user_ids:list<int>}> $teams */
    public function applyBalanced(Game $game, Actor $actor, string $fingerprint, array $teams): Game
    {
        $updated = DB::transaction(function () use ($game, $actor, $fingerprint, $teams): Game {
            [$event, $lockedGame] = $this->lockEditable($game, $actor);
            $this->assertIndividualFormationOpen($lockedGame);
            if (! hash_equals($this->poolFingerprint($lockedGame), $fingerprint)) {
                throw new InvalidArgumentException('Пул участников изменился. Сформируйте preview заново.');
            }
            if (count($teams) !== 2) {
                throw new InvalidArgumentException('Самостоятельная игра должна иметь ровно две стороны.');
            }

            $normalized = collect($teams)->sortBy('number')->values();
            $sideA = $normalized[0];
            $sideB = $normalized[1];
            $this->materializeIndividuals(
                $event,
                $lockedGame,
                $actor,
                array_map('intval', $sideA['user_ids']),
                array_map('intval', $sideB['user_ids']),
                (string) $sideA['name'],
                (string) $sideB['name'],
                (string) $sideA['logo_preset'],
                (string) $sideB['logo_preset'],
            );

            return $lockedGame->fresh(['sides', 'rosterEntries.user.profile', 'admissions']);
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    public function confirmTeams(Game $game, Actor $actor, int $teamAId, int $teamBId): Game
    {
        $updated = DB::transaction(function () use ($game, $actor, $teamAId, $teamBId): Game {
            [$event, $lockedGame] = $this->lockEditable($game, $actor);
            if ($lockedGame->recruitment_mode !== GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                throw new InvalidArgumentException('Готовые команды нельзя утвердить в режиме набора отдельных игроков.');
            }
            $this->assertNotAlreadyConfirmed($lockedGame);
            if ($teamAId === $teamBId) {
                throw new InvalidArgumentException('Одна команда не может занимать обе стороны игры.');
            }

            $accepted = $lockedGame->admissions()
                ->where('candidate_type', GameAdmissionCandidateTypeEnum::TEAM->value)
                ->where('status', GameAdmissionStatusEnum::ACCEPTED->value)
                ->whereIn('team_id', [$teamAId, $teamBId])
                ->pluck('team_id')
                ->map(fn ($id): int => (int) $id)
                ->unique();
            if ($accepted->count() !== 2) {
                throw new InvalidArgumentException('Сначала обе команды должны принять участие или быть подтверждены организатором.');
            }

            $teams = $this->lockTeams(collect([$teamAId, $teamBId]));
            $this->materializeTeams($event, $lockedGame, $teams[$teamAId], $teams[$teamBId], $actor);

            return $lockedGame->fresh(['sides', 'rosterEntries', 'admissions']);
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    /** @param list<int> $sideAUserIds @param list<int> $sideBUserIds */
    public function confirmIndividuals(
        Game $game,
        Actor $actor,
        array $sideAUserIds,
        array $sideBUserIds,
        string $sideAName = 'Команда 1',
        string $sideBName = 'Команда 2',
        string $sideALogoPreset = 'crest-00',
        string $sideBLogoPreset = 'crest-01',
    ): Game {
        $updated = DB::transaction(function () use (
            $game,
            $actor,
            $sideAUserIds,
            $sideBUserIds,
            $sideAName,
            $sideBName,
            $sideALogoPreset,
            $sideBLogoPreset,
        ): Game {
            [$event, $lockedGame] = $this->lockEditable($game, $actor);
            $this->assertIndividualFormationOpen($lockedGame);
            $this->materializeIndividuals(
                $event,
                $lockedGame,
                $actor,
                $sideAUserIds,
                $sideBUserIds,
                $sideAName,
                $sideBName,
                $sideALogoPreset,
                $sideBLogoPreset,
            );

            return $lockedGame->fresh(['sides', 'rosterEntries.user.profile', 'admissions']);
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    public function unconfirm(Game $game, Actor $actor): Game
    {
        $updated = DB::transaction(function () use ($game, $actor): Game {
            [$event, $lockedGame] = $this->lockEditable($game, $actor);
            if ($lockedGame->sides_confirmed_at === null) {
                throw new InvalidArgumentException('Стороны уже находятся в режиме формирования.');
            }
            $formerParticipantIds = $this->clearSides($lockedGame);
            $this->markFormerRosterParticipantsLeft($event, $formerParticipantIds);

            return $lockedGame->fresh(['sides', 'rosterEntries', 'admissions']);
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    public function setApplicationsEnabled(Game $game, Actor $actor, bool $enabled): Game
    {
        $updated = DB::transaction(function () use ($game, $actor, $enabled): Game {
            [, $lockedGame] = $this->lockEditable($game, $actor);
            $lockedGame->forceFill(['accepts_applications' => $enabled])->save();

            return $lockedGame->fresh();
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    public function updateConfiguration(
        Game $game,
        Actor $actor,
        int $sideASize,
        int $sideBSize,
        GameScoringTypeEnum $scoringType,
        GameFormatEnum $format,
        GameTimingModeEnum $timingMode,
        ?int $periodsCount,
    ): Game {
        $updated = DB::transaction(function () use (
            $game,
            $actor,
            $sideASize,
            $sideBSize,
            $scoringType,
            $format,
            $timingMode,
            $periodsCount,
        ): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertStandaloneEditable($event, $lockedGame);
            $this->assertSideSizes($sideASize, $sideBSize);
            $this->assertRecruitmentFormat($lockedGame->recruitment_mode, $sideASize, $sideBSize);

            $sizesChanged = (int) $lockedGame->side_a_size !== $sideASize
                || (int) $lockedGame->side_b_size !== $sideBSize;
            if ($sizesChanged && $lockedGame->sides_confirmed_at !== null) {
                throw new InvalidArgumentException('Сначала снимите утверждение сторон, затем измените размер составов.');
            }

            [$format, $periodsCount] = $this->normalizeFormat(
                $format,
                $sideASize,
                $sideBSize,
                $scoringType,
                $periodsCount,
            );
            $periodsCount = $this->normalizePeriods($timingMode, $periodsCount);
            if ($lockedGame->statistics_status !== GameStatisticsStatusEnum::NOT_STARTED
                || $lockedGame->actions()->exists()
                || $lockedGame->playerStatistics()->exists()) {
                throw new InvalidArgumentException('После появления игровых данных основные параметры изменять нельзя.');
            }

            $lockedGame->periods()->delete();
            $lockedGame->forceFill([
                'format' => $format,
                'timing_mode' => $timingMode,
                'side_a_size' => $sideASize,
                'side_b_size' => $sideBSize,
                'scoring_type' => $scoringType,
                'periods_count' => $periodsCount,
            ])->save();
            $this->createPeriods($lockedGame, $periodsCount);

            return $lockedGame->fresh(['periods', 'sides', 'rosterEntries']);
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    /** @return array{Event, Game} */
    private function lockEditable(Game $game, Actor $actor): array
    {
        $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
        $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
        $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
        $this->assertStandaloneEditable($event, $lockedGame);

        return [$event, $lockedGame];
    }

    private function assertStandaloneEditable(Event $event, Game $game): void
    {
        if ($event->type !== EventTypeEnum::GAME || (int) $event->primary_game_id !== (int) $game->id) {
            throw new InvalidArgumentException('Управление формированием доступно только основной самостоятельной игре.');
        }
        if ($game->status !== GameStatusEnum::SCHEDULED || $game->actual_started_at !== null) {
            throw new InvalidArgumentException('После фактического начала игры стороны и основные настройки изменять нельзя.');
        }
    }

    private function assertIndividualFormationOpen(Game $game): void
    {
        if ($game->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
            throw new InvalidArgumentException('Balanced-формирование доступно только при наборе отдельных игроков.');
        }
        $this->assertNotAlreadyConfirmed($game);
    }

    private function assertNotAlreadyConfirmed(Game $game): void
    {
        if ($game->sides_confirmed_at !== null) {
            throw new InvalidArgumentException('Стороны уже утверждены. Сначала снимите утверждение.');
        }
    }

    /** @param Collection<int, int> $teamIds @return Collection<int, Team> */
    private function lockTeams(Collection $teamIds): Collection
    {
        if ($teamIds->isEmpty()) {
            return collect();
        }
        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($teams->count() !== $teamIds->unique()->count()) {
            throw new InvalidArgumentException('Выбранная команда недоступна или больше не активна.');
        }

        return $teams;
    }

    private function materializeTeams(Event $event, Game $game, Team $teamA, Team $teamB, Actor $actor): void
    {
        if ($teamA->is($teamB)) {
            throw new InvalidArgumentException('Одна команда не может занимать обе стороны игры.');
        }
        if ($game->sides()->exists() || $game->rosterEntries()->exists()) {
            throw new InvalidArgumentException('Перед повторным утверждением сначала снимите текущее утверждение сторон.');
        }

        $sideA = $game->sides()->create([
            'team_id' => $teamA->id,
            'slot' => 'A',
            'display_name' => $teamA->name,
        ]);
        $sideB = $game->sides()->create([
            'team_id' => $teamB->id,
            'slot' => 'B',
            'display_name' => $teamB->name,
        ]);
        $rosterA = $this->teamRoster($teamA);
        $rosterB = $this->teamRoster($teamB)
            ->reject(fn (array $member): bool => $rosterA->has($member['user_id']))
            ->keyBy('user_id');
        $participants = $this->syncEventParticipants(
            $event,
            $rosterA->keys()->merge($rosterB->keys())->values(),
        );

        foreach ([[$sideA, $rosterA], [$sideB, $rosterB]] as [$side, $roster]) {
            foreach ($roster as $member) {
                $game->rosterEntries()->create([
                    'game_side_id' => $side->id,
                    'user_id' => $member['user_id'],
                    'source_contract_membership_id' => $member['membership_id'],
                    'source_event_participant_id' => $participants[$member['user_id']]->id,
                    'status' => GameRosterStatusEnum::SELECTED,
                ]);
            }
        }
        $this->markConfirmed($game, $actor);
    }

    /**
     * @param  list<int>  $sideAUserIds
     * @param  list<int>  $sideBUserIds
     */
    private function materializeIndividuals(
        Event $event,
        Game $game,
        Actor $actor,
        array $sideAUserIds,
        array $sideBUserIds,
        string $sideAName,
        string $sideBName,
        string $sideALogoPreset,
        string $sideBLogoPreset,
    ): void {
        $sideAUserIds = array_values(array_map('intval', $sideAUserIds));
        $sideBUserIds = array_values(array_map('intval', $sideBUserIds));
        $all = collect([...$sideAUserIds, ...$sideBUserIds]);
        if ($all->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Игрок не может повторяться в составе или входить сразу в обе стороны.');
        }
        if (count($sideAUserIds) < (int) $game->side_a_size
            || count($sideBUserIds) < (int) $game->side_b_size) {
            throw new InvalidArgumentException('В каждой стороне должно хватать игроков для стартового состава.');
        }
        $acceptedIds = $this->acceptedIndividualIds($game);
        if ($all->unique()->sort()->values()->all() !== $acceptedIds->all()) {
            throw new InvalidArgumentException('Каждый принятый игрок должен входить ровно в одну утверждаемую команду.');
        }
        if ($game->sides()->exists() || $game->rosterEntries()->exists()) {
            throw new InvalidArgumentException('Перед повторным утверждением сначала снимите текущее утверждение сторон.');
        }

        $participants = $this->syncEventParticipants($event, $acceptedIds);
        $sideA = $game->sides()->create([
            'slot' => 'A',
            'display_name' => $this->sideName($sideAName, 'Команда 1'),
            'logo_preset' => $this->logoPreset($sideALogoPreset, 'crest-00'),
        ]);
        $sideB = $game->sides()->create([
            'slot' => 'B',
            'display_name' => $this->sideName($sideBName, 'Команда 2'),
            'logo_preset' => $this->logoPreset($sideBLogoPreset, 'crest-01'),
        ]);

        foreach ([[$sideA, $sideAUserIds], [$sideB, $sideBUserIds]] as [$side, $ids]) {
            foreach ($ids as $userId) {
                $game->rosterEntries()->create([
                    'game_side_id' => $side->id,
                    'user_id' => $userId,
                    'source_event_participant_id' => $participants[$userId]->id,
                    'status' => GameRosterStatusEnum::SELECTED,
                ]);
            }
        }
        $this->markConfirmed($game, $actor);
    }

    /** @return Collection<int, array{user_id:int, membership_id:int}> */
    private function teamRoster(Team $team): Collection
    {
        return $team->memberships()
            ->with('user')
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn ($membership): array => [
                'user_id' => (int) $membership->user->canonical()->id,
                'membership_id' => (int) $membership->id,
            ])
            ->unique('user_id')
            ->keyBy('user_id');
    }

    /** @return Collection<int, int> */
    private function acceptedIndividualIds(Game $game): Collection
    {
        return $game->admissions()
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
            ->where('status', GameAdmissionStatusEnum::ACCEPTED->value)
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($admission): int => (int) $admission->user->canonical()->id)
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, User> */
    private function acceptedIndividualUsers(Game $game): Collection
    {
        $ids = $this->acceptedIndividualIds($game);

        return User::query()->whereKey($ids)
            ->with(['profile', 'playerProfile.positions', 'playerProfile.selfAssessment', 'playerObjectiveAssessment'])
            ->get()
            ->sortBy(fn (User $user): int => $ids->search($user->id))
            ->values();
    }

    private function poolFingerprint(Game $game): string
    {
        $values = $game->admissions()
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
            ->where('status', GameAdmissionStatusEnum::ACCEPTED->value)
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($item): string => $item->id.':'.$item->user?->canonical()->id.':'.$item->updated_at?->format('U.u'))
            ->join('|');

        return hash('sha256', implode('|', [
            BalancedTeamFormationEngine::FORMULA_VERSION,
            $game->id,
            $game->side_a_size,
            $game->side_b_size,
            $values,
        ]));
    }

    /** @param Collection<int, int> $userIds @return Collection<int, EventParticipant> */
    private function syncEventParticipants(Event $event, Collection $userIds): Collection
    {
        $canonicalIds = User::query()->whereKey($userIds->all())->get()
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->unique()
            ->values();
        $organizerUserId = $event->organizerActor()->with('user')->first()?->user?->canonical()?->id;
        $participants = collect();

        foreach ($canonicalIds->sort() as $userId) {
            $participant = $event->participants()->where('user_id', $userId)->lockForUpdate()->first();
            if ($participant === null) {
                $participant = $event->participants()->create([
                    'user_id' => $userId,
                    'role' => $organizerUserId !== null && $userId === (int) $organizerUserId
                        ? EventParticipantRoleEnum::ORGANIZER
                        : EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'joined_at' => now(),
                    'confirmation_version' => $event->participation_confirmation_version,
                ]);
            } else {
                $participant->forceFill([
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'left_at' => null,
                    'confirmation_version' => $event->participation_confirmation_version,
                ])->save();
            }
            $participants->put($userId, $participant);
        }

        return $participants;
    }

    /** @return Collection<int, int> */
    private function clearSides(Game $game): Collection
    {
        if ($game->actual_started_at !== null) {
            throw new InvalidArgumentException('После начала игры стороны и состав изменять нельзя.');
        }
        if ($game->statistics_status !== GameStatisticsStatusEnum::NOT_STARTED
            || $game->playerStatistics()->exists()
            || $game->actions()->exists()) {
            throw new InvalidArgumentException('Стороны нельзя переутвердить после появления игровых данных.');
        }
        $participantIds = $game->rosterEntries()
            ->whereNotNull('source_event_participant_id')
            ->pluck('source_event_participant_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $game->rosterEntries()->delete();
        $game->sides()->delete();
        $game->forceFill([
            'sides_confirmed_at' => null,
            'sides_confirmed_by_actor_id' => null,
        ])->save();

        return $participantIds;
    }

    /** @param Collection<int, int> $participantIds */
    private function markFormerRosterParticipantsLeft(Event $event, Collection $participantIds): void
    {
        if ($participantIds->isEmpty()) {
            return;
        }
        $organizerUserId = $event->organizerActor()->with('user')->first()?->user?->canonical()?->id;
        $event->participants()
            ->whereIn('id', $participantIds)
            ->when($organizerUserId !== null, fn ($query) => $query->where('user_id', '!=', (int) $organizerUserId))
            ->update([
                'status' => EventParticipantStatusEnum::LEFT->value,
                'left_at' => now(),
            ]);
    }

    private function markConfirmed(Game $game, Actor $actor): void
    {
        $game->forceFill([
            'sides_confirmed_at' => now(),
            'sides_confirmed_by_actor_id' => $actor->id,
        ])->save();
    }

    private function assertSideSizes(int $a, int $b): void
    {
        if ($a < 1 || $a > 7 || $b < 1 || $b > 7) {
            throw new InvalidArgumentException('Количество игроков на каждой стороне должно быть от 1 до 7.');
        }
    }

    private function assertRecruitmentFormat(GameRecruitmentModeEnum $mode, int $a, int $b): void
    {
        if ($mode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT && $a !== $b) {
            throw new InvalidArgumentException('Для balanced-набора размер обеих сторон должен совпадать.');
        }
    }

    /** @return array{GameFormatEnum, int|null} */
    private function normalizeFormat(
        ?GameFormatEnum $format,
        int $a,
        int $b,
        GameScoringTypeEnum $scoring,
        ?int $periods,
    ): array {
        if ($scoring !== GameScoringTypeEnum::BASKETBALL) {
            $periods = null;
        } else {
            $periods ??= 4;
            if ($periods < 1 || $periods > 20) {
                throw new InvalidArgumentException('Количество периодов должно быть от 1 до 20.');
            }
        }
        if ($format === null || $format === GameFormatEnum::CUSTOM
            || $format->sideSize() !== $a
            || $format->sideSize() !== $b
            || $format->scoringType() !== $scoring) {
            $format = GameFormatEnum::CUSTOM;
        }

        return [$format, $periods];
    }

    private function normalizePeriods(GameTimingModeEnum $mode, ?int $periods): ?int
    {
        if ($mode === GameTimingModeEnum::WHOLE_GAME) {
            return null;
        }
        if (! in_array($periods, [2, 4], true)) {
            throw new InvalidArgumentException('Для периодной игры выберите 2 или 4 периода.');
        }

        return $periods;
    }

    private function createPeriods(Game $game, ?int $periods): void
    {
        if ($periods === null) {
            return;
        }
        foreach (range(1, $periods) as $number) {
            $game->periods()->create(['number' => $number]);
        }
    }

    private function sideName(string $name, string $fallback): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name === '' ? $fallback : mb_substr($name, 0, 150);
    }

    private function logoPreset(string $preset, string $fallback): string
    {
        return preg_match('/^crest-(0[0-9]|1[0-4])$/', $preset) === 1 ? $preset : $fallback;
    }
}
