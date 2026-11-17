<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared bearer-only protection for REST and GraphQL; refuses web session fallback.
 */
final class MobileTokenAuthorizer
{
    public function authenticatedUser(string $ability = 'profile:read'): User
    {
        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException('Требуется Bearer-токен пользователя.');
        }

        $canonical = $user->canonical();
        if ((int) $canonical->id !== (int) $user->id
            || User::query()->whereIn('id', $canonical->identityIds())
                ->where('status', UserStatusEnum::BLOCKED->value)->exists()) {
            throw new AuthorizationException('Доступ к аккаунту заблокирован.');
        }

        if (! $token->can($ability)) {
            throw new AuthorizationException('Недостаточно прав токена.');
        }

        return $user;
    }
}
