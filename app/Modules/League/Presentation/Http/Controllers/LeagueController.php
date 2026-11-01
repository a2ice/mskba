<?php

namespace App\Modules\League\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Media\Application\Services\EntityBannerAccess;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class LeagueController extends Controller
{
    /** Public visibility is independent of Filament permissions. Drafts never leak. */
    private const PUBLIC_STATUSES = ['active', 'archived'];

    private const PUBLIC_TOURNAMENT_STATUSES = [
        TournamentStatusEnum::CONFIRMED->value,
        TournamentStatusEnum::CANCELLED->value,
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'season' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? '';
        $seasonId = $filters['season'] ?? null;

        $leagues = League::query()
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->whereLike('name', '%'.$search.'%')
                    ->orWhereLike('description', '%'.$search.'%');
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($seasonId !== null, fn ($query) => $query->whereHas('seasons',
                fn ($seasons) => $seasons->whereKey($seasonId)->whereIn('status', self::PUBLIC_STATUSES)))
            ->withCount([
                'seasons' => fn ($seasons) => $seasons->whereIn('status', self::PUBLIC_STATUSES),
                'teams' => fn ($teams) => $teams->competitionEligible(),
            ])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $seasons = LeagueSeason::query()
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->whereHas('league', fn ($league) => $league->whereIn('status', self::PUBLIC_STATUSES))
            ->with('league:id,name')
            ->orderByDesc('starts_on')
            ->orderBy('name')
            ->get(['id', 'league_id', 'name']);

        return ThemeResolver::page('leagues.index', [
            'leagues' => $leagues,
            'seasons' => $seasons,
            'filters' => ['q' => $search, 'status' => $status, 'season' => $seasonId],
            'canCreateLeague' => $request->user()?->isConfirmed()
                && app(UserOperationalPermissionChecker::class)->allows($request->user(), Permission::CREATE_LEAGUE),
        ]);
    }

    public function show(string $league): Response
    {
        $item = League::query()
            ->where('alias', $league)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->with([
                'teams' => fn ($teams) => $teams->competitionEligible()->with('logo')->orderBy('name'),
                'seasons' => fn ($seasons) => $seasons->whereIn('status', self::PUBLIC_STATUSES)
                    ->withCount(['teams' => fn ($teams) => $teams->competitionEligible()])->orderByDesc('starts_on')->orderByDesc('id'),
                'tournaments' => fn ($tournaments) => $tournaments
                    ->whereIn('status', self::PUBLIC_TOURNAMENT_STATUSES)
                    ->orderByDesc('starts_on')->orderByDesc('id'),
            ])
            ->firstOrFail();

        $user = auth('web')->user();
        $contracts = app(EntityContractManager::class);
        $canEditLeague = $user !== null
            && $item->status !== 'archived'
            && $contracts->allows($user, Scope::LEAGUE, $item->id, LeaguePermissionEnum::EDIT->value);
        return ThemeResolver::page('leagues.show', [
            'league' => $item,
            'canEditLeague' => $user !== null && app(EntityBannerAccess::class)->allows(request(), 'account', $item),
            'entityEditUrl' => $canEditLeague ? route('leagues.manage', $item->id) : null,
            'canCreateSeason' => $user?->isConfirmed()
                && app(UserOperationalPermissionChecker::class)->allows($user, Permission::CREATE_SEASON)
                && $contracts->allows($user, Scope::LEAGUE, $item->id, LeaguePermissionEnum::MANAGE_SEASONS->value),
        ]);
    }

    public function season(string $league, int $season): Response
    {
        $parent = League::query()
            ->where('alias', $league)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->firstOrFail();

        $item = LeagueSeason::query()
            ->whereKey($season)
            ->where('league_id', $parent->id)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->with([
                'teams' => fn ($teams) => $teams->competitionEligible()->with('logo')->orderBy('name'),
                'tournaments' => fn ($tournaments) => $tournaments
                    ->whereIn('status', self::PUBLIC_TOURNAMENT_STATUSES)
                    ->orderByDesc('starts_on')->orderByDesc('id'),
            ])
            ->firstOrFail();

        $user = auth('web')->user();
        $canEditSeason = $user !== null
            && $item->status !== 'archived'
            && app(EntityContractManager::class)->allows($user, Scope::SEASON, $item->id, SeasonPermissionEnum::EDIT->value);
        return ThemeResolver::page('leagues.season', [
            'league' => $parent, 'season' => $item,
            'canEditSeason' => $user !== null && app(EntityBannerAccess::class)->allows(request(), 'account', $item),
            'entityEditUrl' => $canEditSeason ? route('leagues.seasons.manage', [$parent->id, $item->id]) : null,
        ]);
    }
}
