<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\LoginResponseDTO;
use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use App\Modules\Identity\Application\Services\UserLoginResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthHandler
{
    public function __construct(
        private readonly UserLoginResolver $userLoginResolver,
        private readonly CanonicalUserResolver $canonicalUserResolver,
    ) {}

    public function login(string $login, string $password, bool $remember): LoginResponseDTO
    {
        $loginCandidates = $this->userLoginResolver->resolveCandidates($login);

        if ($loginCandidates->isEmpty()) {
            return $this->invalidCredentials();
        }

        $matchingCredentials = $loginCandidates
            ->filter(fn (User $user): bool => $user->password !== null && Hash::check($password, $user->password))
            ->values();

        if ($matchingCredentials->isEmpty()) {
            return $this->invalidCredentials();
        }

        /** @var User $identityUser */
        $identityUser = $loginCandidates->first();
        $canonicalUser = $this->canonicalUserResolver->resolve($identityUser);

        /** @var User|null $loginUser */
        $loginUser = $matchingCredentials
            ->first(fn (User $user): bool => $user->status !== UserStatusEnum::BLOCKED);

        if ($loginUser === null || $canonicalUser->status === UserStatusEnum::BLOCKED) {
            return new LoginResponseDTO(
                status: 'error',
                message: 'Ваш аккаунт заблокирован. Пожалуйста, обратитесь в поддержку.',
                httpStatus: 403,
            );
        }

        Auth::login($canonicalUser, $remember);
        request()->session()->regenerate();

        $firstLoginMarked = User::query()
            ->whereKey($canonicalUser->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $canonicalUser->id));
        }

        if ($loginUser->is_temporary_password) {
            return new LoginResponseDTO(
                status: 'warning',
                message: 'Вы вошли с временным паролем. Пожалуйста, смените пароль в настройках профиля.',
                httpStatus: 200,
            );
        }

        return new LoginResponseDTO(
            status: 'success',
            message: 'Вы успешно вошли в систему.',
            httpStatus: 200,
        );
    }

    public function logout(): void
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    private function invalidCredentials(): LoginResponseDTO
    {
        return new LoginResponseDTO(
            status: 'error',
            message: 'Неверный логин, контакт или пароль.',
            httpStatus: 401,
        );
    }
}
