<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Application\UseCases\CompleteTelegramWebAuthenticationHandler;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class TelegramBotLoginStatusController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBotLoginChallengeStore $challenges,
        CompleteTelegramWebAuthenticationHandler $completeAuthentication,
    ): JsonResponse {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:43'],
        ]);
        $browserKey = (string) $request->session()->get('telegram_bot_login_browser_key');

        if ($browserKey === '') {
            return response()->json([
                'status' => 'expired',
                'message' => 'Ссылка для входа истекла. Запустите вход ещё раз.',
            ], 410);
        }

        try {
            $result = $challenges->consumeForBrowser(
                $validated['token'],
                $browserKey,
                function (array $challenge) use ($completeAuthentication): array {
                    $user = User::query()->findOrFail((int) $challenge['user_id']);
                    $telegramAccount = TelegramAccount::query()
                        ->whereKey((int) $challenge['telegram_account_id'])
                        ->where('user_id', $user->id)
                        ->firstOrFail();

                    $completeAuthentication->handle($user, $telegramAccount);

                    return [
                        'created' => (bool) ($challenge['created'] ?? false),
                        'redirect_url' => (string) $challenge['redirect_url'],
                    ];
                },
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($result['status'] === 'expired') {
            return response()->json([
                'status' => 'expired',
                'message' => 'Ссылка для входа истекла. Запустите вход ещё раз.',
            ], 410);
        }

        if ($result['status'] === 'pending') {
            return response()->json([
                'status' => 'pending',
                'message' => 'Ожидаем подтверждение в Telegram…',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['result']['created']
                ? 'Аккаунт создан. Вы вошли через Telegram.'
                : 'Вы вошли через Telegram.',
            'created' => $result['result']['created'],
            'redirect_url' => $result['result']['redirect_url'],
        ]);
    }
}
