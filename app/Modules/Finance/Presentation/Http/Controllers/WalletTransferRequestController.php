<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class WalletTransferRequestController extends Controller
{
    public function __invoke(
        Request $request,
        SearchDiscoverableUsers $users,
        CreateUserNotificationHandler $notifications,
    ): JsonResponse {
        $data = $request->validate(['recipient_user_id' => ['required', 'integer', 'min:1']]);
        /** @var User $sender */
        $sender = $request->user()->canonical();
        abort_unless($sender->isConfirmed() && ! $sender->isBlocked(), 403);
        $recipient = $users->findVisibleById(
            $sender,
            (int) $data['recipient_user_id'],
            requiredAccess: UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS,
            requiredStatus: UserStatusEnum::CONFIRMED,
        );
        abort_if($recipient === null || $recipient->isBlocked(), 404, 'Пользователь недоступен для запроса.');
        $key = 'wallet-transfer-request:'.$sender->id.':'.$recipient->id;
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'Повторить запрос можно позже.');
        $handle = trim((string) ($sender->nickname ?: $sender->username));
        $senderName = $handle !== '' ? '@'.$handle : 'Участник MSKBA';
        $notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $recipient->id,
            type: UserNotificationTypeEnum::FINANCE,
            title: 'Запрос перевода бонусов',
            body: $senderName.' просит перевести бонусы. Вы можете отказать или самостоятельно сделать перевод из кошелька.',
            actionUrl: route('account.wallet'),
            actionText: 'Открыть кошелёк',
            payload: [
                'source' => 'finance.wallet.transfer.request',
                'requester_user_id' => (int) $sender->id,
                'recipient_user_id' => (int) $recipient->id,
            ],
        ));
        RateLimiter::hit($key, 3600);

        return response()->json(['message' => 'Запрос отправлен. Перевод возможен только по решению получателя.'], 201);
    }
}
