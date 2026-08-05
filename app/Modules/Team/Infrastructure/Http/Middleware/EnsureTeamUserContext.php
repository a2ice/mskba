<?php

namespace App\Modules\Team\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamUserContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('teams.update') && $request->exists('status')) {
            abort(403, 'Статус команды изменяется только в административном разделе.');
        }

        return $next($request);
    }
}
