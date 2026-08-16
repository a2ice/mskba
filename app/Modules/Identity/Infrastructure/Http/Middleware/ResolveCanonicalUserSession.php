<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveCanonicalUserSession
{
    public function __construct(
        private readonly CanonicalUserResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->canonical_user_id !== null) {
            $canonical = $this->resolver->resolve($user);

            if ($canonical->id !== $user->id) {
                Auth::setUser($canonical);
                $request->setUserResolver(fn () => $canonical);
            }
        }

        return $next($request);
    }
}
