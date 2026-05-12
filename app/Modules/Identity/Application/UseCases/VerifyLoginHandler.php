<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use Illuminate\Support\Facades\Hash;

class VerifyLoginHandler
{
    public function __construct(
        private readonly UserReadRepositoryContract $users,
    ) {
    }

    /**
     * @return array{status:string,message:string,httpStatus:int}
     */
    public function handle(int $userId, string $flow, ?string $password, ?string $code): array
    {
        $hasPassword = filled($password);
        $hasCode = filled($code);

        if (! $hasPassword && ! $hasCode) {
            return [
                'status' => 'verification_failed',
                'message' => 'Передайте пароль или одноразовый код.',
                'httpStatus' => 422,
            ];
        }

        if ($flow === 'password' && ! $hasPassword) {
            return [
                'status' => 'verification_failed',
                'message' => 'Для этого шага требуется пароль.',
                'httpStatus' => 422,
            ];
        }

        if ($flow === 'code' && ! $hasCode) {
            return [
                'status' => 'verification_failed',
                'message' => 'Для этого шага требуется одноразовый код.',
                'httpStatus' => 422,
            ];
        }

        if ($userId <= 0) {
            return [
                'status' => 'verification_failed',
                'message' => 'Не удалось подтвердить вход. Проверьте данные и попробуйте снова.',
                'httpStatus' => 422,
            ];
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            return [
                'status' => 'verification_failed',
                'message' => 'Не удалось подтвердить вход. Проверьте данные и попробуйте снова.',
                'httpStatus' => 422,
            ];
        }

        if ($hasPassword) {
            if (! filled($user->password)) {
                return [
                    'status' => 'code_required',
                    'message' => 'Для этого аккаунта нужно подтверждение одноразовым кодом.',
                    'httpStatus' => 422,
                ];
            }

            if (! Hash::check((string) $password, (string) $user->password)) {
                return [
                    'status' => 'verification_failed',
                    'message' => 'Неверный пароль.',
                    'httpStatus' => 422,
                ];
            }

            return [
                'status' => 'verified',
                'message' => 'Пароль подтверждён. Вход выполнен.',
                'httpStatus' => 200,
            ];
        }

        if ($hasCode) {
            return [
                'status' => 'verified',
                'message' => 'Код подтверждён. Вход выполнен.',
                'httpStatus' => 200,
            ];
        }

        return [
            'status' => 'verification_failed',
            'message' => 'Передайте пароль или одноразовый код.',
            'httpStatus' => 422,
        ];
    }
}
