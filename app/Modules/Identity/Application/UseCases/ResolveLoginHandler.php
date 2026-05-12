<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;

class ResolveLoginHandler
{
    public function __construct(
        private readonly ContactValueCheckerContract $contactValueChecker,
        private readonly UserReadRepositoryContract $users,
    ) {
    }

    /**
     * @return array{status:string,message:string,httpStatus:int}
     */
    public function handle(string $login): array
    {
        $normalizedLogin = mb_strtolower(trim($login));
        $isContact = $this->contactValueChecker->isContact($normalizedLogin);

        $user = $this->users->findByResolvedLogin($normalizedLogin, $isContact);

        if ($user !== null) {
            if (filled($user->password)) {
                return [
                    'status' => 'password_required',
                    'message' => 'Пользователь найден. Введите пароль.',
                    'httpStatus' => 200,
                ];
            }

            return [
                'status' => 'code_sent',
                'message' => 'Пароль не установлен. Мы отправили одноразовый код.',
                'httpStatus' => 200,
            ];
        }

        if ($isContact) {
            return [
                'status' => 'code_sent',
                'message' => 'Если контакт существует, мы отправили одноразовый код.',
                'httpStatus' => 200,
            ];
        }

        return [
            'status' => 'user_not_found',
            'message' => 'Пользователь с таким логином не найден.',
            'httpStatus' => 404,
        ];
    }
}
