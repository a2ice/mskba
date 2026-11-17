<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Support\Facades\DB;

final readonly class NotifyWalletTransferRecipient
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function handle(
        WalletOperation $operation,
        User $sender,
        User $recipient,
        int $amountMinor,
        WalletBalanceTypeEnum $balanceType,
    ): void {
        DB::transaction(function () use ($operation, $sender, $recipient, $amountMinor, $balanceType): void {
            // A shared lock serializes notification repair and concurrent replay.
            $locked = WalletOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $exists = UserNotification::query()
                ->where('user_id', $recipient->id)
                ->where('payload->source', 'finance.wallet.transfer.received')
                ->whereJsonContains('payload->wallet_operation_id', (int) $locked->id)
                ->exists();

            if ($exists) {
                return;
            }

            $backoffice = $locked->reference_type === 'backoffice_bonus_transfer';
            $rubles = intdiv($amountMinor, 100);
            $kopeks = $amountMinor % 100;
            $amount = number_format($rubles, 0, ',', ' ').','.str_pad((string) $kopeks, 2, '0', STR_PAD_LEFT).' ₽';
            $handle = trim((string) ($sender->nickname ?: $sender->username));
            $senderLabel = $handle !== '' ? '@'.$handle : 'пользователя #'.$sender->id;

            $this->notifications->handle(new CreateUserNotificationDTO(
                userId: (int) $recipient->id,
                type: UserNotificationTypeEnum::FINANCE,
                title: $backoffice ? 'Баланс пополнен' : 'Получен перевод',
                body: $backoffice
                    ? 'Администрация MSKBA перечислила вам '.$amount.' бонусами.'
                    : $senderLabel.' перевёл вам '.$amount.' бонусами.',
                actionUrl: route('account.wallet', [], false),
                actionText: 'Открыть кошелёк',
                payload: [
                    'source' => 'finance.wallet.transfer.received',
                    'delivery_category' => UserNotificationDeliveryCategoryEnum::SYSTEM->value,
                    'wallet_operation_id' => (int) $locked->id,
                    'sender_user_id' => (int) $sender->id,
                    'amount_minor' => $amountMinor,
                    'balance_type' => $balanceType->value,
                    'transfer_origin' => $backoffice ? 'backoffice' : 'user',
                ],
            ), deferEventUntilCommit: true);
        }, 3);
    }
}
