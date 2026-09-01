<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageSeoResolver;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Models\GameSide;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Team\Application\Services\TeamLogoManager;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamMembershipHierarchy;
use App\Modules\Team\Application\Services\TeamNameAllocator;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TeamController extends Controller
{
    private const int CREATION_LIMIT = 5;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'member_count' => ['nullable', Rule::in(['small', 'medium', 'large'])],
            'sport_type' => ['nullable', Rule::enum(TeamSportTypeEnum::class)],
            'hiring' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $memberCount = (string) ($filters['member_count'] ?? '');
        $sportType = (string) ($filters['sport_type'] ?? '');
        $hiring = $request->boolean('hiring');
        $activeMemberships = fn ($query) => $query
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE));

        return ThemeResolver::page('teams.index', [
            'teams' => Team::query()
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE)
                ->when($sportType !== '', fn ($query) => $query->whereHas(
                    'sportProfiles',
                    fn ($profiles) => $profiles->where('sport_type', $sportType),
                ))
                ->when($hiring, fn ($query) => $query->whereHas('hiringPositions', fn ($positions) => $positions->available()))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->whereLike('name', "%{$search}%")
                        ->orWhereLike('description', "%{$search}%");
                }))
                ->when($memberCount === 'small', fn ($query) => $query->whereHas('memberships', $activeMemberships, '<=', 5))
                ->when($memberCount === 'medium', fn ($query) => $query
                    ->whereHas('memberships', $activeMemberships, '>=', 6)
                    ->whereHas('memberships', $activeMemberships, '<=', 10))
                ->when($memberCount === 'large', fn ($query) => $query->whereHas('memberships', $activeMemberships, '>=', 11))
                ->with([
                    'logo',
                    'sportProfiles.lineupMembers',
                    'memberships' => fn ($memberships) => $memberships
                        ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                        ->whereHas('contract', fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE))
                        ->with(['contract', 'user.profile']),
                ])
                ->withCount(['memberships as active_memberships_count' => $activeMemberships])
                ->withCount(['hiringPositions as active_hiring_positions_count' => fn ($positions) => $positions->available()])
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['q' => $search, 'member_count' => $memberCount, 'sport_type' => $sportType, 'hiring' => $hiring],
        ]);
    }

    public function create(): Response
    {
        return ThemeResolver::page('teams.create', ['sportTypes' => TeamSportTypeEnum::cases()]);
    }

    public function store(
        Request $request,
        CurrentActorResolver $actors,
        CyrillicTransliterator $transliterator,
        TeamNameAllocator $names,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sport_types' => ['nullable', 'array', 'min:1'],
            'sport_types.*' => ['required', 'distinct', Rule::enum(TeamSportTypeEnum::class)],
        ]);
        $sportTypes = $data['sport_types'] ?? [TeamSportTypeEnum::BASKETBALL->value];
        unset($data['sport_types']);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor?->user_id === null, 403);
        $identityIds = $actor->user?->canonical()->identityIds() ?? [];
        if (! $request->user()?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            $teamCount = Team::query()
                ->whereNull('temporary_for_event_id')
                ->whereHas('createdByActor', fn ($query) => $query->whereIn('user_id', $identityIds))
                ->count();
            abort_if(
                $teamCount >= self::CREATION_LIMIT,
                422,
                'Достигнут лимит: можно создать не более 5 команд.',
            );
        }

        $hadDuplicate = false;
        try {
            $team = DB::transaction(function () use ($data, $sportTypes, $actor, $transliterator, $names, &$hadDuplicate): Team {
                $allocatedName = $names->allocate($data['name'], $actor->user_id);
                $hadDuplicate = $allocatedName['has_duplicate'];
                $base = Str::slug($transliterator->transliterate($allocatedName['name'])) ?: 'team';
                unset($allocatedName['has_duplicate']);
                $alias = $base;
                $suffix = 2;
                while (Team::withTrashed()->where('alias', $alias)->exists()) {
                    $alias = "{$base}-{$suffix}";
                    $suffix++;
                }

                $team = Team::create([
                    ...$data,
                    ...$allocatedName,
                    'alias' => $alias,
                    'created_by_actor_id' => $actor->id,
                    'status' => TeamStatusEnum::ACTIVE,
                ]);
                $this->syncSportTypes($team, $sportTypes);
                $contract = Contract::create([
                    'family' => ContractFamilyEnum::MEMBERSHIP,
                    'name' => "Владелец команды «{$team->name}»",
                    'status' => ContractStatusEnum::ACTIVE,
                    'assigned_by' => $actor->user_id,
                    'assigner' => UserParticipationRoleAssignerEnum::USER,
                ]);
                $contract->membership()->create([
                    'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                    'scope_id' => $team->id,
                    'user_id' => $actor->user_id,
                    'access_level' => TeamMembershipAccessLevelEnum::OWNER->value,
                    'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
                ]);

                return $team;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        $message = $hadDuplicate
            ? "Команда создана как «{$team->name}», поскольку исходное название уже используется."
            : 'Команда создана.';

        return redirect()->route('teams.show', $team->routeIdentifier())->with('status', $message);
    }

    public function suggestName(Request $request, TeamNameAllocator $names, CurrentActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:140'],
            'except' => ['nullable', 'integer', 'exists:teams,id'],
        ]);
        $except = isset($data['except']) ? Team::query()->find($data['except']) : null;
        $actor = $actors->resolveForRequest($request);

        return response()->json($names->suggest(
            (string) ($data['name'] ?? ''),
            $except,
            $actor?->user_id,
        ));
    }

    public function show(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        PageSeoResolver $pageSeo,
    ): Response {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with([
                'logo',
                'sportProfiles.lineupMembers',
                'memberships.contract.permissions',
                'memberships.user.profile.activeAvatar',
                'venueRelations' => fn ($relations) => $relations
                    ->where('relation_type', TeamVenueRelationTypeEnum::DESIRED)
                    ->whereHas('venue', fn ($venues) => $venues->where('status', VenueStatusEnum::CONFIRMED))
                    ->with('venue'),
                'hiringPositions' => fn ($positions) => $positions->available()->oldest(),
            ])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        $activeMemberships = $item->memberships
            ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE
                && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED)
            ->values();
        $coaches = $activeMemberships
            ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::COACH))
            ->values();
        $managers = $activeMemberships
            ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::MANAGER))
            ->values();
        $players = $activeMemberships
            ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::PLAYER))
            ->sortBy('id')
            ->values();
        $startingLineups = $item->sportProfiles
            ->mapWithKeys(function ($profile) use ($players): array {
                $size = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;
                $assignments = $profile->lineupMembers->keyBy('contract_membership_id');
                $ordered = $players->sortBy(fn ($player) => sprintf('%d-%010d', $assignments->get($player->id)?->position ?? 9999, $player->id))->values();
                $starters = $ordered->filter(fn ($player) => $assignments->get($player->id)?->assignment === TeamLineupAssignmentEnum::STARTER)->values();
                $reserves = $ordered->reject(fn ($player) => $starters->contains('id', $player->id))->values();

                return [$profile->sport_type->value => [
                    'label' => $profile->sport_type->label(),
                    'size' => $size,
                    'sport_type' => $profile->sport_type->value,
                    'starters' => $starters,
                    'reserves' => $reserves,
                    'is_complete' => $players->count() >= $size && $starters->count() === $size,
                ]];
            });

        return ThemeResolver::page('teams.show', [
            'team' => $item,
            'coaches' => $coaches,
            'managers' => $managers,
            'activeMemberships' => $activeMemberships,
            'players' => $players,
            'startingLineups' => $startingLineups,
            'hasCompleteRoster' => $startingLineups->every('is_complete'),
            'canManage' => $actor !== null && $access->canManage($item, $actor),
            'canManageMembersAndRoster' => $actor !== null && $access->canManageMembersAndRoster($item, $actor),
            'canManageVenues' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES),
            'canManageHiring' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING),
            'canManageRoster' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROSTER),
            'canInviteMembers' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS),
            'canManageRoles' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROLES),
            'canManagePermissions' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS),
            'canRemoveMembers' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::REMOVE_MEMBERS),
            'currentUserId' => $actor?->user_id,
            'teamPermissions' => TeamPermissionEnum::cases(),
            'roles' => TeamMembershipAccessLevelEnum::cases(),
            'sportTypes' => TeamSportTypeEnum::cases(),
            ...$pageSeo->resolve(
                SeoEntityTypeEnum::TEAM,
                $item->id,
                $item->name,
                $item->description,
                route('teams.show', $item->routeIdentifier()),
            ),
        ]);
    }

    public function edit(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): Response
    {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with(['logo', 'sportProfiles', 'memberships.contract', 'memberships.user.profile'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS), 403);

        return ThemeResolver::page('teams.edit', [
            'team' => $item,
            'sportTypes' => TeamSportTypeEnum::cases(),
            'canModerateStatus' => $actor->user?->isAdmin() ?? false,
            'canDeleteTeam' => $access->isCreator($item, $actor)
                && $item->status === TeamStatusEnum::ACTIVE,
            'canManageMembersAndRoster' => $access->canManageMembersAndRoster($item, $actor),
            'canManageJoinRequests' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
            'canManageVenues' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES),
            'canManageHiring' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING),
        ]);
    }

    public function update(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access, TeamNameAllocator $names): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $canModerateStatus = $actor->user?->isAdmin() ?? false;
        abort_if(! $canModerateStatus && $request->exists('status'), 403, 'Изменять статус команды может только администратор.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [$canModerateStatus ? 'required' : 'missing', Rule::enum(TeamStatusEnum::class)],
            'sport_types' => ['nullable', 'array', 'min:1'],
            'sport_types.*' => ['required', 'distinct', Rule::enum(TeamSportTypeEnum::class)],
        ]);
        $data['status'] ??= $item->status->value;
        $sportTypes = $data['sport_types'] ?? $item->sportProfiles()->pluck('sport_type')->all();
        $sportTypes = $sportTypes ?: [TeamSportTypeEnum::BASKETBALL->value];
        unset($data['sport_types']);
        try {
            DB::transaction(function () use ($item, $data, $sportTypes, $names, $canModerateStatus): void {
                $lockedTeam = Team::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if (! $canModerateStatus) {
                    $data['name'] = $lockedTeam->base_name ?? $lockedTeam->name;
                }
                if ($data['status'] === TeamStatusEnum::ACTIVE->value) {
                    $hasManager = $lockedTeam->memberships()
                        ->whereIn('access_level', [
                            TeamMembershipAccessLevelEnum::OWNER->value,
                            TeamMembershipAccessLevelEnum::RESPONSIBLE->value,
                            TeamMembershipAccessLevelEnum::CAPTAIN->value,
                        ])
                        ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                        ->exists();
                    if (! $hasManager) {
                        throw new InvalidArgumentException('Для активной постоянной команды нужен владелец, ответственный или капитан.');
                    }
                    $requestedNormalized = $names->normalize($data['name']);
                    if ($canModerateStatus && ($lockedTeam->status !== TeamStatusEnum::ACTIVE || $lockedTeam->normalized_name !== $requestedNormalized)) {
                        $creatorUserId = (int) $lockedTeam->createdByActor()->value('user_id');
                        $allocatedName = $names->allocate($data['name'], $creatorUserId, $lockedTeam);
                        unset($allocatedName['has_duplicate']);
                        $data = [...$data, ...$allocatedName];
                    } else {
                        $data['base_name'] = $names->clean($data['name']);
                        $data['name'] = $lockedTeam->name_sequence > 1
                            ? "{$data['base_name']} №{$lockedTeam->name_sequence}"
                            : $data['base_name'];
                    }
                } else {
                    $data['base_name'] = $names->clean($data['name']);
                    $data['normalized_name'] = $names->normalize($data['name']);
                    $data['name_sequence'] = null;
                    $data['name'] = $data['base_name'];
                }
                $lockedTeam->update($data);
                $this->syncSportTypes($lockedTeam, $sportTypes);
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Команда обновлена.');
    }

    public function destroy(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->isCreator($item, $actor), 403);
        abort_if($item->status !== TeamStatusEnum::ACTIVE, 409, 'Удалить можно только активную команду.');

        if (GameSide::query()->where('team_id', $item->id)->exists()) {
            return back()->with('error', 'Команду нельзя удалить: она участвует или участвовала в мероприятиях. Сначала удалите все связанные участия.');
        }

        $item->update(['status' => TeamStatusEnum::DRAFT]);

        return redirect()->route('account.teams')->with('status', 'Команда удалена и перенесена в черновики.');
    }

    public function storeLogo(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        TeamLogoManager $logos,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $data = $request->validate([
            'logo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ]);

        try {
            $contents = file_get_contents($data['logo']->getRealPath());
            if (! is_string($contents)) {
                throw new InvalidArgumentException('Не удалось прочитать выбранный файл.');
            }
            $logos->store($item, $actor, $contents);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Логотип команды обновлён.');
    }

    public function destroyLogo(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        TeamLogoManager $logos,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $logos->delete($item, $actor);

        return back()->with('status', 'Логотип команды удалён.');
    }

    public function removeMember(
        string $team,
        int $membership,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        TeamMembershipHierarchy $hierarchy,
    ): RedirectResponse|JsonResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::REMOVE_MEMBERS), 403);
        $member = $item->memberships()->whereKey($membership)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->firstOrFail();
        abort_if($member->access_level === TeamMembershipAccessLevelEnum::OWNER->value, 422, 'Владельца команды удалить нельзя.');
        abort_if($member->is_captain, 422, 'Капитана нельзя исключить из команды. Сначала назначьте другого капитана.');
        $identityIds = $actor->user?->canonical()->identityIds() ?? [];
        abort_if(in_array((int) $member->user_id, $identityIds, true), 422, 'Нельзя исключить самого себя через управление командой.');
        if (! $access->isCreator($item, $actor)) {
            $actorMembership = $item->memberships()
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->first();
            abort_if(
                $actorMembership === null || ! $hierarchy->canRemove($actorMembership, $member),
                422,
                'Нельзя исключить участника с равным или более высоким уровнем управления.',
            );
        }

        DB::transaction(function () use ($member): void {
            $lockedMember = ContractMembership::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $lockedMember->contract()->lockForUpdate()->firstOrFail()->update(['status' => ContractStatusEnum::INACTIVE]);
            $lockedMember->sportLineupAssignments()->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Участник исключён из команды.', 'membership_id' => $member->id]);
        }

        return back()->with('status', 'Участник исключён из команды.');
    }

    /** @param array<int, string> $sportTypes */
    private function syncSportTypes(Team $team, array $sportTypes): void
    {
        $values = collect($sportTypes)->unique()->values();
        foreach ($values as $sportType) {
            $profile = $team->sportProfiles()->updateOrCreate(['sport_type' => $sportType]);
            $playerIds = $team->memberships()
                ->withSportRole(TeamMemberTypeEnum::PLAYER)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->orderBy('id')->pluck('id');
            foreach ($playerIds as $position => $membershipId) {
                $profile->lineupMembers()->firstOrCreate(
                    ['contract_membership_id' => $membershipId],
                    ['assignment' => TeamLineupAssignmentEnum::RESERVE, 'position' => $position],
                );
            }
        }
        $team->sportProfiles()->whereNotIn('sport_type', $values)->delete();
    }
}
