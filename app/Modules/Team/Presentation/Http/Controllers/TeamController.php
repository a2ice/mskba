<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
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
    public function index(): Response
    {
        return ThemeResolver::page('teams.index', [
            'teams' => Team::query()
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE)
                ->with('logo')
                ->withCount('memberships')
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    public function create(): Response
    {
        return ThemeResolver::page('teams.create', ['statuses' => TeamStatusEnum::cases()]);
    }

    public function store(
        Request $request,
        CurrentActorResolver $actors,
        CyrillicTransliterator $transliterator,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor?->user_id === null, 403);

        $team = DB::transaction(function () use ($data, $actor, $transliterator): Team {
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

    public function show(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): Response
    {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with(['logo', 'memberships.contract', 'memberships.user.profile.activeAvatar'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);

        return ThemeResolver::page('teams.show', [
            'team' => $item,
            'canManage' => $actor !== null && $access->canManage($item, $actor),
            'roles' => TeamMembershipAccessLevelEnum::cases(),
        ]);
    }

    public function edit(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): Response
    {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with(['logo', 'memberships.contract', 'memberships.user.profile'])
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);

        return ThemeResolver::page('teams.edit', [
            'team' => $item,
            'roles' => TeamMembershipAccessLevelEnum::cases(),
            'users' => User::query()->with('profile')->orderBy('username')->limit(200)->get(),
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
        ]);
        try {
            DB::transaction(function () use ($item, $data): void {
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
}
