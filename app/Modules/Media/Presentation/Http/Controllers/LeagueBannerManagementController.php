<?php

namespace App\Modules\Media\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Media\Application\Services\EntityBannerAccess;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LeagueBannerManagementController extends Controller
{
    public function league(Request $request, int $league, EntityBannerAccess $access): Response
    {
        $model = League::query()->with('seasons')->findOrFail($league);
        abort_unless($access->allows($request, 'account', $model), 403);

        return ThemeResolver::page('leagues.manage-banner', [
            'league' => $model,
            'season' => null,
            'bannerModel' => $model,
        ]);
    }

    public function season(Request $request, int $league, int $season, EntityBannerAccess $access): Response
    {
        $parent = League::query()->findOrFail($league);
        $model = LeagueSeason::query()->where('league_id', $parent->id)->findOrFail($season);
        abort_unless($access->allows($request, 'account', $model), 403);

        return ThemeResolver::page('leagues.manage-banner', [
            'league' => $parent,
            'season' => $model,
            'bannerModel' => $model,
        ]);
    }
}
