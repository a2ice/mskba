<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telegram\Application\UseCases\LinkTelegramIdentityHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class LinkTelegramIdentityController extends Controller
{
    public function __invoke(Request $request, LinkTelegramIdentityHandler $link): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user' => ['required', 'array'],
            'telegram_user.id' => ['required'],
            'telegram_user.first_name' => ['nullable', 'string'],
            'telegram_user.last_name' => ['nullable', 'string'],
            'telegram_user.username' => ['nullable', 'string'],
            'telegram_user.photo_url' => ['nullable', 'string'],
            'telegram_user.auth_date' => ['required'],
            'telegram_user.hash' => ['required', 'string'],
        ]);

        try {
            $result = $link->handle($request->user(), $validated['telegram_user']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($result['status'] === 'duplicate') {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'Этот Telegram уже связан с другим аккаунтом MSKBA. Можно проверить и объединить аккаунты.',
                'duplicate_id' => $result['duplicate']?->id,
                'redirect_url' => $result['duplicate'] === null
                    ? route('account')
                    : route('account.user-duplicates.show', $result['duplicate']),
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Telegram подтверждён и привязан к вашему аккаунту.',
            'redirect_url' => route('account.contacts'),
        ]);
    }
}
