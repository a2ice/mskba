<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class StartTelegramBotLinkController extends Controller
{
    public function __invoke(Request $request, TelegramBotLoginChallengeStore $challenges): JsonResponse
    {
        $username = ltrim(trim((string) config('telegram.bot_username')), '@');
        abort_if($username === '', 503, 'Telegram bot is not configured.');

        $browserKey = (string) $request->session()->get('telegram_bot_link_browser_key');
        if ($browserKey === '') {
            $browserKey = Str::random(40);
            $request->session()->put('telegram_bot_link_browser_key', $browserKey);
        }

        $issued = $challenges->createLink($browserKey, (int) $request->user()->canonical()->id);

        return response()->json([
            'status' => 'pending',
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'bot_url' => "https://t.me/{$username}?start=link_{$issued['token']}",
        ]);
    }
}
