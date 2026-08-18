<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
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

        if ($user === null) {
            return $next($request);
        }

        $canonical = $this->resolver->resolve($user);

        if ($canonical->status === UserStatusEnum::BLOCKED) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->setUserResolver(fn () => null);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Аккаунт заблокирован.'], 403);
            }

            abort(403, 'Аккаунт заблокирован.');
        }

        if ($canonical->id !== $user->id) {
            Auth::setUser($canonical);
            $request->setUserResolver(fn () => $canonical);
        }

        return $next($request);
    }
}
