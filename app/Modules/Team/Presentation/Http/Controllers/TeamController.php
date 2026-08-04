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
use App\Modules\Event\Domain\Models\GameSide;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Team\Application\Services\TeamLogoManager;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'member_count' => ['nullable', Rule::in(['small', 'medium', 'large'])],
            'sport_type' => ['nullable', Rule::enum(TeamSportTypeEnum::class)],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $memberCount = (string) ($filters['member_count'] ?? '');
        $sportType = (string) ($filters['sport_type'] ?? '');
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
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['q' => $search, 'member_count' => $memberCount, 'sport_type' => $sportType],
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
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sport_types' => ['nullable', 'array', 'min:1'],
            'sport_types.*' => ['required', 'distinct', Rule::enum(TeamSportTypeEnum::class)],
        ]);
        $sportTypes = $data['sport_types'] ?? [TeamSportTypeEnum::BASKETBALL->value];
        unset($data['sport_types']);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor?->user_id === null, 403);

        $team = DB::transaction(function () use ($data, $sportTypes, $actor, $transliterator): Team {
            $base = Str::slug($transliterator->transliterate($data['name'])) ?: 'team';
            $alias = $base;
            $suffix = 2;
            while (Team::withTrashed()->where('alias', $alias)->exists()) {
                $alias = "{$base}-{$suffix}";
                $suffix++;
            }

            $team = Team::create([
                ...$data,
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
                'member_type' => TeamMemberTypeEnum::MANAGER,
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ]);

            return $team;
        });

        return redirect()->route('teams.show', $team->routeIdentifier())->with('status', 'Команда создана.');
    }

    public function show(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        PageSeoResolver $pageSeo,
    ): Response {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with(['logo', 'sportProfiles.lineupMembers', 'memberships.contract.permissions', 'memberships.user.profile.activeAvatar'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        $activeMemberships = $item->memberships
            ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE
                && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED)
            ->values();
        $coaches = $activeMemberships
            ->filter(fn ($membership) => $membership->member_type === TeamMemberTypeEnum::COACH
                || $membership->access_level === TeamMembershipAccessLevelEnum::COACH->value)
            ->values();
        $managers = $activeMemberships
            ->filter(fn ($membership) => $membership->member_type === TeamMemberTypeEnum::MANAGER)
            ->values();
        $players = $activeMemberships
            ->filter(fn ($membership) => $membership->member_type === TeamMemberTypeEnum::PLAYER)
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
            'players' => $players,
            'startingLineups' => $startingLineups,
            'hasCompleteRoster' => $startingLineups->every('is_complete'),
            'canManage' => $actor !== null && $access->canManage($item, $actor),
            'canManageRoster' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROSTER),
            'canInviteMembers' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS),
            'canManageRoles' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROLES),
            'canManagePermissions' => $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS),
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
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);

        return ThemeResolver::page('teams.edit', [
            'team' => $item,
            'sportTypes' => TeamSportTypeEnum::cases(),
            'canModerateStatus' => $actor->user?->isAdmin() ?? false,
        ]);
    }

    public function update(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $canModerateStatus = $actor->user?->isAdmin() ?? false;
        abort_if(! $canModerateStatus && $request->exists('status'), 403, 'Изменять статус команды может только администратор.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
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
            DB::transaction(function () use ($item, $data, $sportTypes): void {
                $lockedTeam = Team::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
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
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
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

    public function removeMember(string $team, int $membership, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS), 403);
        $member = $item->memberships()->whereKey($membership)->firstOrFail();
        abort_if($member->access_level === TeamMembershipAccessLevelEnum::OWNER->value, 422, 'Владельца команды удалить нельзя.');
        $member->contract->update(['status' => ContractStatusEnum::INACTIVE]);
        $member->sportLineupAssignments()->delete();

        return back()->with('status', 'Участник исключён из активного состава.');
    }

    /** @param array<int, string> $sportTypes */
    private function syncSportTypes(Team $team, array $sportTypes): void
    {
        $values = collect($sportTypes)->unique()->values();
        foreach ($values as $sportType) {
            $profile = $team->sportProfiles()->updateOrCreate(['sport_type' => $sportType]);
            $playerIds = $team->memberships()
                ->where('member_type', TeamMemberTypeEnum::PLAYER->value)
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
