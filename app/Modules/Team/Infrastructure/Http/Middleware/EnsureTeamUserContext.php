<?php

namespace App\Modules\Team\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamUserContext
{
    public function __construct(
        private readonly CurrentActorResolver $actors,
        private readonly TeamManagementAccess $access,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('teams.update') && $request->exists('status')) {
            abort(403, 'Статус команды изменяется только в административном разделе.');
        }

        if ($request->routeIs(
            'teams.edit',
            'teams.update',
            'teams.logo.store',
            'teams.logo.destroy',
            'teams.settings.applications.update',
        )) {
            $identifier = (string) $request->route('team');
            $team = Team::query()->whereRouteIdentifier($identifier)->firstOrFail();
            $actor = $this->actors->resolveForRequest($request);
            abort_if($actor === null || ! $this->access->allows($team, $actor, TeamPermissionEnum::EDIT_SETTINGS), 403);
        }

        return $next($request);
    }
}
