<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthFlowController extends Controller
{
    public function resolveLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $login = trim((string) $data['login']);
        $normalizedLogin = mb_strtolower($login);

        $isContact = filter_var($normalizedLogin, FILTER_VALIDATE_EMAIL) !== false
            || preg_match('/^\+?[0-9][0-9\-\s\(\)]{8,}$/', $normalizedLogin) === 1;

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
