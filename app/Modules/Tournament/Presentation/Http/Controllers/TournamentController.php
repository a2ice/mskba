<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Application\Services\TournamentCoverManager;
use App\Modules\Tournament\Application\Services\TournamentEntryRosterResolver;
use App\Modules\Tournament\Application\Services\TournamentLifecycleService;
use App\Modules\Tournament\Application\Services\TournamentPlayerCharacteristics;
use App\Modules\Tournament\Application\Services\TournamentStaffService;
use App\Modules\Tournament\Application\Services\TournamentStandingsService;
use App\Modules\Tournament\Application\UseCases\ChangeTournamentStatusHandler;
use App\Modules\Tournament\Application\UseCases\CreateTournamentHandler;
use App\Modules\Tournament\Application\UseCases\DeleteTournamentHandler;
use App\Modules\Tournament\Application\UseCases\UpdateTournamentHandler;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TournamentController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'current', 'upcoming', 'past'])],
            'query' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), ['after_or_equal:date_from'])],
        ]);
        $period = $validated['period'] ?? 'all';
        $now = now();
        $tournaments = Tournament::query()
            ->whereIn('status', [TournamentStatusEnum::CONFIRMED->value, TournamentStatusEnum::CANCELLED->value])
            ->with('cover')
            ->when($validated['query'] ?? null, fn ($query, $value) => $query->where('title', 'like', '%'.$value.'%'))
            ->when($validated['date_from'] ?? null, fn ($query, $value) => $query->where('starts_on', '>=', $value))
            ->when($validated['date_to'] ?? null, fn ($query, $value) => $query->where('starts_on', '<=', $value))
            ->when($period === 'upcoming', fn ($query) => $query
                ->whereNull('tournament_closed_at')
                ->where('starts_on', '>', $now->toDateString()))
            ->when($period === 'current', fn ($query) => $query
                ->whereNull('tournament_closed_at')
                ->where('starts_on', '<=', $now->toDateString())
                ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhere('ends_on', '>=', $now->toDateString())))
            ->when($period === 'past', fn ($query) => $query->where(fn ($past) => $past
                ->whereNotNull('tournament_closed_at')
                ->orWhere(fn ($dated) => $dated->whereNotNull('ends_on')->where('ends_on', '<', $now->toDateString()))))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return ThemeResolver::page('tournaments.index', [
            'period' => $period,
            'query' => $validated['query'] ?? null,
            'dateFrom' => $validated['date_from'] ?? null,
            'dateTo' => $validated['date_to'] ?? null,
            'tournaments' => $tournaments,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->status === UserStatusEnum::CONFIRMED, 403);

        return ThemeResolver::page('tournaments.create', [
            'formats' => $this->formats(),
            'recruitmentModes' => TournamentRecruitmentModeEnum::cases(),
            'enrollmentPolicies' => TournamentEnrollmentPolicyEnum::cases(),
        ]);
    }

    public function store(
        Request $request,
        CreateTournamentHandler $handler,
        TournamentCoverManager $covers,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $tournament = $handler->handle($actor, $this->payload($request));
            if ($request->hasFile('cover')) {
                $covers->replace($tournament, $actor, (string) file_get_contents($request->file('cover')->getRealPath()));
            }
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('tournaments.show', $tournament->routeIdentifier())
            ->with('status', 'Турнир создан.');
    }

    public function show(
        Request $request,
        string $tournament,
        CurrentActorResolver $actors,
        TournamentAccess $access,
        TeamManagementAccess $teamAccess,
        TournamentStandingsService $standings,
        TournamentEntryRosterResolver $entryRosters,
    ): Response {
        $item = Tournament::query()->whereRouteIdentifier($tournament)
            ->with(['createdByActor.user.profile', 'cover', 'entries.team.logo', 'entries.logo', 'entries.members.user.profile', 'matches.entryA', 'matches.entryB', 'matches.game.event.venue', 'matches.game.event.booking', 'matches.game.sides.team.logo'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        $canManage = $actor !== null && $access->canManage($item, $actor);
        abort_if($item->status === TournamentStatusEnum::UNCONFIRMED && ! $canManage, 404);
        $item->entries->each(fn ($entry) => $entry->setRelation('effectiveMembers', $entryRosters->resolveUsers($entry)));
        $identityIds = $request->user()?->canonical()->identityIds() ?? [];
        $myPendingInvitations = $identityIds === [] ? collect() : $item->admissions()
            ->with('team')
            ->where('direction', 'invitation')
            ->where('status', 'pending')
            ->get()
            ->filter(fn ($admission): bool => in_array((int) $admission->user_id, $identityIds, true)
                || ($actor !== null
                    && $admission->team !== null
                    && $teamAccess->allows($admission->team, $actor, TeamPermissionEnum::MANAGE_TOURNAMENT_PARTICIPATION)))
            ->values();
        $hasActiveApplication = $identityIds !== [] && $item->admissions()
            ->where('direction', TournamentAdmissionDirectionEnum::APPLICATION->value)
            ->whereIn('user_id', $identityIds)
            ->whereIn('status', [
                TournamentAdmissionStatusEnum::PENDING->value,
                TournamentAdmissionStatusEnum::ACCEPTED->value,
            ])
            ->exists();
        $teamApplicationOptions = collect();
        if ($actor !== null
            && $item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
            && $item->acceptsAdmissions()) {
            $alreadyAppliedTeamIds = $item->admissions()
                ->whereNotNull('team_id')
                ->whereIn('status', [TournamentAdmissionStatusEnum::PENDING->value, TournamentAdmissionStatusEnum::ACCEPTED->value])
                ->pluck('team_id');
            $teamApplicationOptions = Team::query()
                ->competitionInvitable()
                ->whereNotIn('id', $alreadyAppliedTeamIds)
                ->orderBy('name')
                ->get()
                ->filter(fn (Team $team): bool => $teamAccess->allows($team, $actor, TeamPermissionEnum::MANAGE_TOURNAMENT_PARTICIPATION))
                ->values();
        }
        $publicParticipantCount = $item->recruitment_mode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
            ? $item->admissions()
                ->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)
                ->whereNotNull('user_id')
                ->with('user')
                ->get()
                ->map(fn ($admission): ?int => $admission->user?->canonical()->id)
                ->filter()
                ->unique()
                ->count()
            : $item->entries->count();

        return ThemeResolver::page('tournaments.show', [
            'tournament' => $item,
            'canManage' => $canManage,
            'canApplyAsPlayer' => $request->user() !== null
                && $item->recruitment_mode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
                && $item->acceptsAdmissions()
                && ! $hasActiveApplication,
            'teamApplicationOptions' => $teamApplicationOptions,
            'admissionRoles' => TournamentAdmissionRoleEnum::cases(),
            'myPendingInvitations' => $myPendingInvitations,
            'publicParticipantCount' => $publicParticipantCount,
            'standings' => $standings->build($item),
        ]);
    }

    public function manage(
        Request $request,
        string $tournament,
        CurrentActorResolver $actors,
        TournamentAccess $access,
        TournamentEntryRosterResolver $entryRosters,
        TournamentPlayerCharacteristics $characteristics,
    ): Response {
        $item = Tournament::query()
            ->with(['defaultVenue.location.address', 'defaultVenue.characteristics'])
            ->whereRouteIdentifier($tournament)
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $identityIds = $request->user()?->canonical()->identityIds() ?? [];
        $pendingMembership = $identityIds === [] ? null : $item->staffMemberships()
            ->whereIn('user_id', $identityIds)
            ->where('invitation_status', TeamInvitationStatusEnum::PENDING->value)
            ->with('contract.permissions')
            ->first();
        abort_if(! $access->canManage($item, $actor) && $pendingMembership === null, 403);
        $effectivePermissions = collect($access->effectivePermissions($item, $actor));
        $entries = $item->entries()->get();
        $matches = $item->matches()->with(['entryA', 'entryB', 'game.sides', 'game.event.venue.location.address', 'game.event.venue.characteristics', 'game.event.booking'])->get();
        $acceptedPlayerCount = $item->admissions()
            ->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->map(fn ($admission): int => $admission->user->canonical()->id)
            ->unique()
            ->count();
        $competitionStarted = $matches->contains(fn ($match): bool => $match->game?->actual_started_at !== null || in_array($match->game?->status, [GameStatusEnum::IN_PROGRESS, GameStatusEnum::AWAITING_RESULT, GameStatusEnum::COMPLETED], true));
        $participantEntryNames = collect();
        $entries->each(function ($entry) use ($entryRosters, $participantEntryNames): void {
            $members = $entryRosters->resolveUsers($entry);
            $entry->setRelation('effectiveMembers', $members);
            $entry->setAttribute('effective_members_count', $members->count());
            $members->each(fn ($member) => $participantEntryNames->put($member->id, $entry->name));
        });

        $admissions = $item->admissions()->with(['team', 'user.profile', 'user.playerProfile.positions', 'user.playerProfile.selfAssessment', 'entry'])->latest('id')->get();
        $admissions->whereNotNull('user_id')->each(function ($admission): void {
            $admission->setRelation('user', $admission->user->canonical()->loadMissing([
                'profile',
                'playerProfile.positions',
                'playerProfile.selfAssessment',
            ]));
        });
        $hasMatches = $matches->isNotEmpty();
        $matchesAvailable = $entries->count() >= 2 && ($item->isContinuous() || $item->participant_pool_locked_at !== null);

        return ThemeResolver::page('tournaments.manage', [
            'tournament' => $item,
            'formats' => $this->formats(),
            'statuses' => TournamentStatusEnum::cases(),
            'recruitmentModes' => TournamentRecruitmentModeEnum::cases(),
            'enrollmentPolicies' => TournamentEnrollmentPolicyEnum::cases(),
            'permissionOptions' => TournamentPermissionEnum::cases(),
            'effectivePermissions' => $effectivePermissions,
            'isOwner' => $access->isOwner($item, $actor),
            'pendingMembership' => $pendingMembership,
            'staffMemberships' => $item->staffMemberships()
                ->with(['user.profile', 'contract.permissions'])
                ->latest('id')->get(),
            'admissions' => $admissions,
            'admissionCharacteristics' => $admissions->whereNotNull('user_id')->mapWithKeys(fn ($admission): array => [$admission->user_id => $characteristics->forUser($admission->user)]),
            'entries' => $entries,
            'participantEntryNames' => $participantEntryNames,
            'matches' => $matches,
            'assessmentSources' => TournamentAssessmentSourceEnum::cases(),
            'acceptedPlayerCount' => $acceptedPlayerCount,
            'preparationStatus' => $this->preparationStatus($item, $entries, $matches, $acceptedPlayerCount),
            'canManageGames' => $access->allows($item, $actor, TournamentPermissionEnum::MANAGE_GAMES),
            'acceptsAdmissions' => $item->acceptsAdmissions(),
            'structuralSettingsLocked' => $item->participant_pool_locked_at !== null || $hasMatches,
            'recruitmentSettingLocked' => $item->admissions()->exists() || $hasMatches,
            'enrollmentSettingLocked' => $item->admissions()->exists() || $hasMatches,
            'roundRobinSettingLocked' => $hasMatches,
            'admissionSettingLocked' => ! $item->acceptsAdmissions() || $item->participant_pool_locked_at !== null,
            'participantPoolLocked' => $item->participant_pool_locked_at !== null,
            'matchesAvailable' => $matchesAvailable,
            'startsDateLocked' => $competitionStarted,
            'endsDateLocked' => $competitionStarted && ! $item->isContinuous(),
            'datesFullyLocked' => $competitionStarted && ! $item->isContinuous(),
            'competitionStarted' => $competitionStarted,
        ]);
    }

    public function update(
        Request $request,
        string $tournament,
        UpdateTournamentHandler $handler,
        TournamentCoverManager $covers,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $item = $handler->handle($tournament, $actor, $this->payload($request));
            if ($request->hasFile('cover')) {
                $covers->replace($item, $actor, (string) file_get_contents($request->file('cover')->getRealPath()));
            }
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('tournaments.manage', $item->routeIdentifier())->with('status', 'Турнир обновлён.');
    }

    public function status(
        Request $request,
        string $tournament,
        ChangeTournamentStatusHandler $handler,
        TournamentLifecycleService $lifecycle,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $data = $request->validate([
            'lifecycle_action' => ['nullable', Rule::in(['close_tournament'])],
            'status' => ['nullable', 'required_without:lifecycle_action', Rule::enum(TournamentStatusEnum::class)],
            'status_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            if (($data['lifecycle_action'] ?? null) === 'close_tournament') {
                $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
                $lifecycle->close($item, $actor);

                return back()->with('status', 'Турнир завершён. Итоговая таблица зафиксирована текущими подтверждёнными результатами.');
            }
            $handler->handle($tournament, $actor, TournamentStatusEnum::from($data['status']), $data['status_comment'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Статус турнира обновлён.');
    }

    public function destroy(
        Request $request,
        string $tournament,
        DeleteTournamentHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $data = $request->validate([
            'deletion_reason' => ['required', 'string', 'max:2000'],
        ], [
            'deletion_reason.required' => 'Укажите причину удаления турнира.',
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $handler->handle($tournament, $actor, $data['deletion_reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('tournaments.index')->with('status', 'Турнир удалён.');
    }

    public function inviteStaff(
        Request $request,
        string $tournament,
        TournamentStaffService $staff,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::enum(TournamentPermissionEnum::class)],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $user = User::query()->whereKey($data['user_id'])->where('status', UserStatusEnum::CONFIRMED->value)->first();
        if ($user === null) {
            return back()->with('error', 'Подтверждённый пользователь с таким логином не найден.');
        }
        try {
            $staff->invite($item, $user, $actor, $data['permissions']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Приглашение отправлено.');
    }

    public function respondStaff(
        Request $request,
        string $tournament,
        ContractMembership $membership,
        TournamentStaffService $staff,
    ): RedirectResponse {
        $data = $request->validate(['decision' => ['required', Rule::in([
            TeamInvitationStatusEnum::ACCEPTED->value,
            TeamInvitationStatusEnum::DECLINED->value,
        ])]]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        try {
            $staff->respond($item, $membership, $request->user(), TeamInvitationStatusEnum::from($data['decision']));
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('tournaments.show', $item->routeIdentifier())->with('status', 'Ответ сохранён.');
    }

    public function updateStaff(
        Request $request,
        string $tournament,
        ContractMembership $membership,
        TournamentStaffService $staff,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::enum(TournamentPermissionEnum::class)],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        try {
            $staff->updatePermissions($item, $membership, $actor, $data['permissions']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Права обновлены.');
    }

    public function revokeStaff(
        Request $request,
        string $tournament,
        ContractMembership $membership,
        TournamentStaffService $staff,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        try {
            $staff->revoke($item, $membership, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Договор отозван.');
    }

    /** @return array<string, mixed> */
    private function preparationStatus(Tournament $tournament, Collection $entries, Collection $matches, int $acceptedPlayerCount): array
    {
        if ($tournament->status === TournamentStatusEnum::CANCELLED) {
            return ['label' => 'Турнир отменён', 'modifier' => 'is-danger', 'icon' => 'ti-circle-x'];
        }
        if ($tournament->tournament_closed_at !== null) {
            return ['label' => 'Турнир завершён', 'modifier' => 'is-complete', 'icon' => 'ti-circle-check'];
        }
        if (! $tournament->isContinuous() && $matches->isNotEmpty() && $matches->every(fn ($match): bool => $match->game?->status === GameStatusEnum::COMPLETED)) {
            return ['label' => 'Турнир завершён', 'modifier' => 'is-complete', 'icon' => 'ti-circle-check'];
        }
        if ($matches->contains(fn ($match): bool => $match->game?->actual_started_at !== null || in_array($match->game?->status, [GameStatusEnum::IN_PROGRESS, GameStatusEnum::AWAITING_RESULT, GameStatusEnum::COMPLETED], true))) {
            return ['label' => $tournament->isContinuous() && $tournament->recruitment_closed_at === null ? 'Турнир идёт · набор открыт' : 'Турнир идёт', 'modifier' => 'is-live', 'icon' => 'ti-player-play'];
        }
        if ($matches->isNotEmpty() && $matches->every(fn ($match): bool => $match->game_id !== null)) {
            return ['label' => 'Расписание готово', 'modifier' => 'is-ready', 'icon' => 'ti-calendar-check'];
        }
        if ($matches->contains(fn ($match): bool => $match->game_id !== null)) {
            return ['label' => 'Назначение матчей', 'modifier' => 'is-progress', 'icon' => 'ti-calendar-time'];
        }
        if ($matches->isNotEmpty()) {
            return ['label' => $tournament->isContinuous() ? 'Открытая лига · матчи сформированы' : 'Схема сформирована', 'modifier' => 'is-progress', 'icon' => 'ti-tournament'];
        }
        if ($tournament->participant_pool_locked_at !== null) {
            return ['label' => $tournament->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'Команды определены' : 'Команды сформированы', 'modifier' => 'is-progress', 'icon' => 'ti-users-group'];
        }
        if ($entries->isNotEmpty()) {
            return ['label' => $tournament->isContinuous() && $tournament->recruitment_closed_at === null ? 'Открытая лига · набор команд' : ($tournament->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'Набор команд' : 'Участники определены'), 'modifier' => 'is-progress', 'icon' => 'ti-users'];
        }
        if ($acceptedPlayerCount > 0) {
            return ['label' => 'Набор участников', 'modifier' => 'is-pending', 'icon' => 'ti-user-plus'];
        }

        return ['label' => $tournament->isContinuous() ? 'Открытая лига · набор открыт' : 'Подготовка турнира', 'modifier' => 'is-pending', 'icon' => 'ti-settings'];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'alias' => ['nullable', 'string', 'max:180'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'default_venue_id' => [
                'nullable',
                'integer',
                Rule::exists('venues', 'id')->where(fn ($query) => $query
                    ->where('status', VenueStatusEnum::CONFIRMED->value)
                    ->whereNull('deleted_at')),
            ],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'full_description' => ['nullable', 'string', 'max:20000'],
            'format' => ['nullable', Rule::in($this->formats()->pluck('value')->all())],
            'recruitment_mode' => ['nullable', Rule::enum(TournamentRecruitmentModeEnum::class)],
            'enrollment_policy' => ['nullable', Rule::enum(TournamentEnrollmentPolicyEnum::class)],
            'round_robin_legs' => ['nullable', 'integer', Rule::in([1, 2])],
            'accepts_unconfirmed_participants' => ['nullable', 'boolean'],
            'cover' => ['nullable', 'image', 'max:10240'],
        ]);
    }

    /** @return Collection<int, GameFormatEnum> */
    private function formats(): Collection
    {
        return collect(GameFormatEnum::cases())->reject(fn (GameFormatEnum $format) => $format === GameFormatEnum::CUSTOM)->values();
    }
}
