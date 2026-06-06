<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\LoginResponseDTO;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthHandler
{
    public function login(string $login, string $password, bool $remember): LoginResponseDTO
    {
        $user = User::where('username', $login)->first();

        if ($user === null) {
            return new LoginResponseDTO(
                status: 'error',
                message: 'Пользователь с таким логином не найден.',
                httpStatus: 404,
            );
        }

        if ($user->status === UserStatusEnum::BLOCKED) {
            return new LoginResponseDTO(
                status: 'error',
                message: 'Ваш аккаунт заблокирован. Пожалуйста, обратитесь в поддержку.',
                httpStatus: 403,
            );
        }

        if ($user->password === null || ! Hash::check($password, $user->password)) {
            return new LoginResponseDTO(
                status: 'error',
                message: 'Неверный пароль. Пожалуйста, попробуйте снова.',
                httpStatus: 401,
            );
        }

        Auth::login($user, $remember);
        request()->session()->regenerate();

        $firstLoginMarked = User::query()
            ->whereKey($user->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $user->id));
        }

        if ($user->is_temporary_password) {
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
}
