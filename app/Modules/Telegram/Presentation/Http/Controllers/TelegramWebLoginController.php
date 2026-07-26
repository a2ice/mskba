<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Presentation\Http\Support\SafeAuthenticationRedirectResolver;
use App\Modules\Telegram\Application\UseCases\AuthenticateTelegramWebUserHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class TelegramWebLoginController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticateTelegramWebUserHandler $authenticate,
        SafeAuthenticationRedirectResolver $redirects,
    ): JsonResponse {
        $validated = $request->validate([
            'telegram_user' => ['required', 'array'],
            'telegram_user.id' => ['required'],
            'telegram_user.first_name' => ['nullable', 'string'],
            'telegram_user.last_name' => ['nullable', 'string'],
            'telegram_user.username' => ['nullable', 'string'],
            'telegram_user.photo_url' => ['nullable', 'string'],
            'telegram_user.auth_date' => ['required'],
            'telegram_user.hash' => ['required', 'string'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $result = $authenticate->handle($validated['telegram_user']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'redirect_url' => null,
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['created']
                ? 'Аккаунт создан. Вы вошли через Telegram.'
                : 'Вы вошли через Telegram.',
            'created' => $result['created'],
            'redirect_url' => $redirects->resolve(
                $request,
                $validated['redirect_to'] ?? null,
                route('account'),
            ),
        ]);
    }
}
