<?php

namespace App\Modules\League\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LeagueManagementController extends Controller
{
    private function authorizeCreation(Request $request, Permission $permission): void
    {
        abort_unless($request->user()?->isConfirmed()
            && app(UserOperationalPermissionChecker::class)->allows($request->user(), $permission), 403);
    }

    private function authorizeLeague(Request $request, League $league, LeaguePermissionEnum $permission): void
    {
        abort_unless(! $league->trashed() && $league->status !== 'archived'
            && app(EntityContractManager::class)->allows($request->user(), Scope::LEAGUE, $league->id, $permission->value), 403);
    }

    private function authorizeSeason(Request $request, LeagueSeason $season): void
    {
        abort_unless(! $season->trashed() && $season->status !== 'archived'
            && app(EntityContractManager::class)->allows($request->user(), Scope::SEASON, $season->id,
                SeasonPermissionEnum::EDIT->value), 403);
    }

    public function createLeague(Request $request): Response
    {
        $this->authorizeCreation($request, Permission::CREATE_LEAGUE);
        return ThemeResolver::page('leagues.edit', ['league' => null]);
    }

    public function storeLeague(Request $request): RedirectResponse
    {
        $this->authorizeCreation($request, Permission::CREATE_LEAGUE);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $user = $request->user()->canonical();
        $alias = Str::slug(app(CyrillicTransliterator::class)->transliterate($data['name'])) ?: 'league';

        $league = DB::transaction(function () use ($data, $user, $alias): League {
            $candidate = $alias;
            $n = 2;
            while (League::withTrashed()->where('alias', $candidate)->exists()) {
                $candidate = $alias.'-'.$n++;
            }
            $league = League::query()->create([
                'name' => trim($data['name']),
                'alias' => $candidate,
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'owner_user_id' => $user->id,
                'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($user, null)?->id,
            ]);
            app(EntityContractManager::class)->grantOwner(
                Scope::LEAGUE, $league->id, $user, $user, 'Владелец лиги «'.$league->name.'»',
            );
            return $league;
        });

        return redirect()->route('leagues.manage', $league->id)->with('status', 'Лига создана.');
    }

    public function editLeague(Request $request, int $league): Response
    {
        $model = League::query()->findOrFail($league);
        $this->authorizeLeague($request, $model, LeaguePermissionEnum::EDIT);
        return ThemeResolver::page('leagues.edit', ['league' => $model]);
    }

    public function updateLeague(Request $request, int $league): RedirectResponse
    {
        $model = League::query()->findOrFail($league);
        $this->authorizeLeague($request, $model, LeaguePermissionEnum::EDIT);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $oldLogo = $model->logo_path;
        $newLogo = $request->hasFile('logo')
            ? $request->file('logo')->store('leagues/logos', 'public')
            : null;

        try {
            $model->update([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'logo_path' => $newLogo ?? $oldLogo,
            ]);
        } catch (\Throwable $exception) {
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $exception;
        }

        if ($newLogo && $oldLogo && $oldLogo !== $newLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        return back()->with('status', 'Изменения сохранены.');
    }

    public function createSeason(Request $request, int $league): Response
    {
        $this->authorizeCreation($request, Permission::CREATE_SEASON);
        $parent = League::query()->findOrFail($league);
        $this->authorizeLeague($request, $parent, LeaguePermissionEnum::MANAGE_SEASONS);
        return ThemeResolver::page('leagues.season-edit', ['league' => $parent, 'season' => null]);
    }

    public function storeSeason(Request $request, int $league): RedirectResponse
    {
        $this->authorizeCreation($request, Permission::CREATE_SEASON);
        $parent = League::query()->findOrFail($league);
        $this->authorizeLeague($request, $parent, LeaguePermissionEnum::MANAGE_SEASONS);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $creator = $request->user()->canonical();
        $owner = $parent->owner?->canonical();
        abort_unless($owner !== null, 422);

        $season = DB::transaction(function () use ($parent, $data, $creator, $owner): LeagueSeason {
            $season = $parent->seasons()->create([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'status' => 'draft',
            ]);
            app(EntityContractManager::class)->grantOwner(Scope::SEASON, $season->id, $owner, $creator,
                'Владелец сезона «'.$season->name.'»');
            return $season;
        });

        $mayEdit = app(EntityContractManager::class)->allows(
            $creator, Scope::SEASON, $season->id, SeasonPermissionEnum::EDIT->value,
        );
        return redirect()->to($mayEdit
            ? route('leagues.seasons.manage', [$parent->id, $season->id])
            : route('account.contracts'))
            ->with('status', 'Сезон создан.');
    }

    public function editSeason(Request $request, int $league, int $season): Response
    {
        $parent = League::query()->findOrFail($league);
        $model = $parent->seasons()->findOrFail($season);
        $this->authorizeSeason($request, $model);
        return ThemeResolver::page('leagues.season-edit', ['league' => $parent, 'season' => $model]);
    }

    public function updateSeason(Request $request, int $league, int $season): RedirectResponse
    {
        $parent = League::query()->findOrFail($league);
        $model = $parent->seasons()->findOrFail($season);
        $this->authorizeSeason($request, $model);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $model->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ]);
        return back()->with('status', 'Изменения сохранены.');
    }
}
