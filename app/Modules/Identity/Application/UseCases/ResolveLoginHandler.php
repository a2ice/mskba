<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
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
        * @return array{status:string,message:string,httpStatus:int,user_id?:int,verify_flow?:string}
     */
    public function handle(string $login): array
    {
        $normalizedLogin = mb_strtolower(trim($login));
        $isContact = $this->contactValueChecker->isContact($normalizedLogin);

        $user = $this->users->findByResolvedLogin($normalizedLogin, $isContact);

        if ($user !== null) {
            if ($isContact) {
                $matchedContact = $user->contacts->first();

                if ($matchedContact !== null && $matchedContact->status !== ContactStatusEnum::VERIFIED) {
                    return [
                        'status' => 'code_sent',
                        'message' => 'Контакт найден, но не подтверждён. Мы отправили одноразовый код.',
                        'httpStatus' => 200,
                        'user_id' => $user->id,
                        'verify_flow' => 'code',
                    ];
                }
            }

            if (filled($user->password)) {
                return [
                    'status' => 'password_required',
                    'message' => 'Пользователь найден. Введите пароль.',
                    'httpStatus' => 200,
                    'user_id' => $user->id,
                    'verify_flow' => 'password',
                ];
            }

            return [
                'status' => 'code_sent',
                'message' => 'Пароль не установлен. Мы отправили одноразовый код.',
                'httpStatus' => 200,
                'user_id' => $user->id,
                'verify_flow' => 'code',
            ];
        }

        if ($isContact) {
            return [
                'status' => 'code_sent',
                'message' => 'Если контакт существует, мы отправили одноразовый код.',
                'httpStatus' => 200,
                'user_id' => 0,
                'verify_flow' => 'code',
            ];
        }

        return [
            'status' => 'user_not_found',
            'message' => 'Пользователь с таким логином не найден.',
            'httpStatus' => 404,
        ];
    }
}
