<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Modules\Access\Application\Services\Authorization\AdminAccess;

class AdminMiddleware
{

    public function __construct(
        private AdminAccess $adminAccess,
    ) {}

    public function handle(Request $request, Closure $next, string $ability = 'access-admin-panel'): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->can($ability)) {
            abort(403, 'Forbidden');
        }

        return $next($request);

    }
}
