<?php

namespace App\Modules\Team\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamUserContext
{
    private const CREATION_LIMIT = 5;

    public function __construct(
        private readonly CurrentActorResolver $actors,
        private readonly TeamManagementAccess $access,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('teams.create', 'teams.store', 'teams.name-suggestion')) {
            $user = $request->user();
            if ($user !== null && ! $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
                $createdTeamsCount = Team::query()
                    ->whereNull('temporary_for_event_id')
                    ->whereHas('createdByActor', fn ($actor) => $actor->where('user_id', $user->id))
                    ->count();

                abort_if(
                    $createdTeamsCount >= self::CREATION_LIMIT,
                    422,
                    'Достигнут лимит: можно создать не более 5 команд.',
                );
            }
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
