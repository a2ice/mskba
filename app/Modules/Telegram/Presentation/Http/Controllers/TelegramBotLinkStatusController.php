<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telegram\Application\DTO\TelegramLoginWidgetUserDTO;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Application\UseCases\LinkTelegramIdentityHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class TelegramBotLinkStatusController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBotLoginChallengeStore $challenges,
        LinkTelegramIdentityHandler $link,
    ): JsonResponse {
        $data = $request->validate(['token' => ['required', 'string', 'size:43']]);
        $browserKey = (string) $request->session()->get('telegram_bot_link_browser_key');
        $currentUser = $request->user()->canonical();
        if ($browserKey === '') {
            return response()->json(['status' => 'expired', 'message' => 'Ссылка истекла. Начни привязку заново.'], 410);
        }

        try {
            $result = $challenges->consumeForBrowser(
                $data['token'],
                $browserKey,
                function (array $challenge) use ($currentUser, $link): array {
                    if ((int) ($challenge['initiator_user_id'] ?? 0) !== (int) $currentUser->id) {
                        throw new InvalidArgumentException('Данная привязка принадлежит другому аккаунту.');
                    }
                    $telegram = $challenge['telegram_user'] ?? [];
                    if (! is_array($telegram) || ! is_numeric($telegram['id'] ?? null)) {
                        throw new InvalidArgumentException('Telegram не подтвердил пользователя.');
                    }
                    $verified = new TelegramLoginWidgetUserDTO(
                        id: (int) $telegram['id'],
                        username: $telegram['username'] ?? null,
                        firstName: $telegram['first_name'] ?? null,
                        lastName: $telegram['last_name'] ?? null,
                        photoUrl: null,
                        authDate: now()->timestamp,
                        rawUser: $telegram,
                    );

                    $linked = $link->linkVerifiedUser($currentUser, $verified);
                    return [
                        'status' => $linked['status'],
                        'duplicate_id' => $linked['duplicate']?->id,
                    ];
                },
                'link',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        if ($result['status'] === 'expired') {
            return response()->json(['status' => 'expired', 'message' => 'Ссылка истекла. Начни привязку заново.'], 410);
        }
        if ($result['status'] === 'pending') {
            return response()->json(['status' => 'pending', 'message' => 'Ожидаем подтверждения в Telegram…']);
        }

        if ($result['result']['status'] === 'duplicate') {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'Telegram уже привязан к другому аккаунту. Необходимо проверить совпадение аккаунтов.',
                'redirect_url' => route('account.user-duplicates.show', $result['result']['duplicate_id']),
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Telegram подтверждён и добавлен к текущему аккаунту.',
        ]);
    }
}
