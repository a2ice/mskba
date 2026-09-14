<?php

namespace App\Modules\Team\Infrastructure\Http\Middleware;

use App\Modules\Team\Domain\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamAcceptsJoinRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->route('team');
        $team = $identifier instanceof Team
            ? $identifier
            : Team::query()->whereRouteIdentifier((string) $identifier)->firstOrFail();

        abort_unless($team->accepts_join_requests, 422, 'Команда сейчас не принимает заявки.');

        return $next($request);
    }
}
