<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceCreationOperationalPermissions
{
    public function __construct(
        private readonly EnsureOperationalPermission $guard,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $permission = match ($routeName) {
            'events.create',
            'events.store',
            'events.wizard',
            'events.wizard.teams',
            'events.wizard.venues' => UserOperationalPermissionEnum::CREATE_EVENT,
            'tournaments.create',
            'tournaments.store' => UserOperationalPermissionEnum::CREATE_TOURNAMENT,
            default => null,
        };

        if ($permission === null) {
            return $next($request);
        }

        return $this->guard->handle($request, $next, $permission->value);
    }
}
