<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamSettingsController extends Controller
{
    public function updateApplications(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS), 403);

        $data = $request->validate([
            'accepts_join_requests' => ['required', 'boolean'],
        ]);
        $item->update(['accepts_join_requests' => (bool) $data['accepts_join_requests']]);

        return back()->with('status', 'Настройки приёма заявок обновлены.');
    }
}
