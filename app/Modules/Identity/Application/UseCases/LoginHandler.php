<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginHandler
{
    public function __construct(
        private readonly ContactValueCheckerContract $contactValueChecker,
        private readonly UserReadRepositoryContract $users,
    ) {
    }

    /**
     * @return array{status:string,message:string,httpStatus:int}
     */
    public function handle(string $login, string $password, bool $remember): array
    {
        $normalizedLogin = mb_strtolower(trim($login));
        $isContact = $this->contactValueChecker->isContact($normalizedLogin);
        $user = $this->users->findByLoginOrContact($normalizedLogin, $isContact);

        if ($user === null) {
            return [
                'status' => 'auth_failed',
                'message' => 'Логин не найден.',
                'httpStatus' => 404,
            ];
        }

        if ($user->status === UserStatusEnum::BLOCKED) {
            return [
                'status' => 'user_blocked',
                'message' => 'Аккаунт заблокирован.',
                'httpStatus' => 403,
            ];
        }

        if ($user->status !== UserStatusEnum::CONFIRMED) {
            return [
                'status' => 'user_unconfirmed',
                'message' => 'Аккаунт не подтверждён.',
                'httpStatus' => 403,
            ];
        }

        if (
            ! filled($user->password)
            || ! Hash::check($password, (string) $user->password)
        ) {
            return [
                'status' => 'auth_failed',
                'message' => 'Неверный логин или пароль.',
                'httpStatus' => 422,
            ];
        }

        Auth::login($user, $remember);

        return [
            'status' => 'authenticated',
            'message' => 'Вход выполнен.',
            'httpStatus' => 200,
        ];
    }
}
