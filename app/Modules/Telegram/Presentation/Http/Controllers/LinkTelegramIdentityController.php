<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\OperationalPermissionIntentResolver;
use App\Modules\Identity\Application\Services\UserDuplicateSelfServiceProofStore;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Telegram\Application\UseCases\LinkTelegramIdentityHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class LinkTelegramIdentityController extends Controller
{
    public function __invoke(
        Request $request,
        LinkTelegramIdentityHandler $link,
        UserDuplicateSelfServiceProofStore $proofs,
        OperationalPermissionIntentResolver $creationIntent,
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
            $candidate = $result['duplicate'];

            if ($candidate === null || $candidate->status !== UserDuplicateStatusEnum::PENDING) {
                return response()->json([
                    'status' => 'reviewed_conflict',
                    'message' => 'Этот Telegram уже связан с другим аккаунтом MSKBA, но эта пара ранее была рассмотрена. Обратитесь к администратору, если аккаунты всё же принадлежат вам.',
                ], 409);
            }

            try {
                $proofs->issue(
                    candidate: $candidate,
                    actor: $request->user(),
                    telegramUserId: (int) $result['telegram_account']->telegram_user_id,
                    sessionId: $request->session()->getId(),
                );
            } catch (InvalidArgumentException $exception) {
                return response()->json([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return response()->json([
                'status' => 'duplicate',
                'message' => 'Этот Telegram уже связан с другим аккаунтом MSKBA. Можно проверить и объединить аккаунты.',
                'duplicate_id' => $candidate->id,
                'redirect_url' => route('account.user-duplicates.show', $candidate),
            ], 409);
        }

        $resumeUrl = $creationIntent->consumeAllowedReturnUrl($request);

        return response()->json([
            'status' => 'success',
            'message' => $resumeUrl === null
                ? 'Telegram подтверждён и привязан к вашему аккаунту.'
                : 'Telegram подтверждён. Право на создание включено — можно продолжить.',
            'redirect_url' => $resumeUrl ?? route('account.contacts'),
        ]);
    }
}
