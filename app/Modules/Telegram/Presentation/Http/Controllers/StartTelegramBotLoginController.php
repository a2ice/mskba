<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Presentation\Http\Support\SafeAuthenticationRedirectResolver;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class StartTelegramBotLoginController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBotLoginChallengeStore $challenges,
        SafeAuthenticationRedirectResolver $redirects,
    ): JsonResponse {
        $validated = $request->validate([
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ]);
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        abort_if($botUsername === '', 503, 'Telegram bot is not configured.');

        $browserKey = (string) $request->session()->get('telegram_bot_login_browser_key');

        if ($browserKey === '') {
            $browserKey = Str::random(40);
            $request->session()->put('telegram_bot_login_browser_key', $browserKey);
        }

        $challenge = $challenges->create(
            $browserKey,
            $redirects->resolve($request, $validated['redirect_to'] ?? null, route('account')),
        );

        return response()->json([
            'status' => 'pending',
            'message' => 'Подтвердите вход в боте. Оставьте страницу открытой.',
            'token' => $challenge['token'],
            'expires_at' => $challenge['expires_at'],
            'bot_url' => "https://t.me/{$botUsername}?start=login_{$challenge['token']}",
        ]);
    }
}
