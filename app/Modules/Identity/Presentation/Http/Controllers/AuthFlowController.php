<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use App\Modules\Identity\Presentation\Http\Requests\ResolveLoginRequest;
use Illuminate\Http\JsonResponse;

class AuthFlowController
{
    public function __construct(
        private readonly ContactValueCheckerContract $contactValueChecker,
    ) {
    }

    public function resolveLogin(ResolveLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $login = trim((string) $data['login']);
        $normalizedLogin = mb_strtolower($login);

        $isContact = $this->contactValueChecker->isContact($normalizedLogin);

        

        $knownUsers = [
            'demo' => true,
            'coach' => false,
            'player' => true,
        ];

        if (array_key_exists($normalizedLogin, $knownUsers)) {
            if ($knownUsers[$normalizedLogin]) {
                return response()->json([
                    'status' => 'password_required',
                    'message' => 'Пользователь найден. Введите пароль.',
                ]);
            }

            return response()->json([
                'status' => 'code_sent',
                'message' => 'Пароль не установлен. Мы отправили одноразовый код.',
            ]);
        }

        if ($isContact) {
            return response()->json([
                'status' => 'code_sent',
                'message' => 'Если контакт существует, мы отправили одноразовый код.',
            ]);
        }

        return response()->json([
            'status' => 'user_not_found',
            'message' => 'Пользователь с таким логином не найден.',
        ], 404);
    }
}
