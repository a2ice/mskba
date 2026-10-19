<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Reject stateful web-session fallback and any revoked/blocked identity. */
final class EnsureActiveMobileApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => 'Требуется Bearer-токен пользователя.'], 401);
        }

        $canonical = $user->canonical();
        if ((int) $canonical->id !== (int) $user->id
            || User::query()->whereIn('id', $canonical->identityIds())
                ->where('status', UserStatusEnum::BLOCKED->value)->exists()) {
            return response()->json(['message' => 'Доступ к аккаунту заблокирован.'], 403);
        }

        $required = $request->routeIs('api.v1.auth.logout') ? 'auth:logout' : 'profile:read';
        if (! $token->can($required)) {
            return response()->json(['message' => 'Недостаточно прав токена.'], 403);
        }

        return $next($request);
    }
}
