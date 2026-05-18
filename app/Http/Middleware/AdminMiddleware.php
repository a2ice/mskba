<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->status !== UserStatusEnum::CONFIRMED) {
            abort(403, 'Forbidden: Unconfirmed account');
        }

        if(! $user->system_role || ! $user->system_role?->atLeast(UserSystemRoleEnum::ADMIN)) {
            abort(403, 'Forbidden: Insufficient permissions');
        }

        return $next($request);
    }
}
