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
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamLogoManager;
use App\Modules\Team\Application\Services\TeamManagementAccess;
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
        $activeMemberships = fn ($query) => $query->whereHas(
            'contract',
            fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE),
        );

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
                    'sportProfiles',
                    'memberships' => fn ($memberships) => $memberships
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
            ->with(['logo', 'sportProfiles', 'memberships.contract', 'memberships.user.profile.activeAvatar'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);

        return ThemeResolver::page('teams.show', [
            'team' => $item,
            'canManage' => $actor !== null && $access->canManage($item, $actor),
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
            'roles' => TeamMembershipAccessLevelEnum::cases(),
            'users' => User::query()->with('profile')->orderBy('username')->limit(200)->get(),
            'sportTypes' => TeamSportTypeEnum::cases(),
        ]);
    }

    public function update(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(TeamStatusEnum::class)],
            'sport_types' => ['nullable', 'array', 'min:1'],
            'sport_types.*' => ['required', 'distinct', Rule::enum(TeamSportTypeEnum::class)],
        ]);
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

    public function addMember(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'access_level' => ['required', Rule::enum(TeamMembershipAccessLevelEnum::class)],
        ]);

        DB::transaction(function () use ($item, $data, $actor): void {
            $existing = $item->memberships()->where('user_id', $data['user_id'])->first();
            if ($existing) {
                $existing->update(['access_level' => $data['access_level']]);
                $existing->contract()->update(['status' => ContractStatusEnum::ACTIVE]);

                return;
            }
            $contract = Contract::create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "Участник команды «{$item->name}»",
                'status' => ContractStatusEnum::ACTIVE,
                'assigned_by' => $actor->user_id,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                'scope_id' => $item->id,
                'user_id' => $data['user_id'],
                'access_level' => $data['access_level'],
            ]);
        });

        return back()->with('status', 'Состав команды обновлён.');
    }

    public function removeMember(string $team, int $membership, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): RedirectResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);
        $member = $item->memberships()->whereKey($membership)->firstOrFail();
        abort_if($member->access_level === TeamMembershipAccessLevelEnum::OWNER->value, 422, 'Владельца команды удалить нельзя.');
        $member->contract->update(['status' => ContractStatusEnum::INACTIVE]);

        return back()->with('status', 'Участник исключён из активного состава.');
    }

    /** @param array<int, string> $sportTypes */
    private function syncSportTypes(Team $team, array $sportTypes): void
    {
        $values = collect($sportTypes)->unique()->values();
        foreach ($values as $sportType) {
            $team->sportProfiles()->updateOrCreate(['sport_type' => $sportType]);
        }
        $team->sportProfiles()->whereNotIn('sport_type', $values)->delete();
    }
}
