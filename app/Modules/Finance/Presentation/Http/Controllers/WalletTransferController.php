<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\Services\ResolveWalletTransferRecipient;
use App\Modules\Finance\Application\Services\RubleAmountParser;
use App\Modules\Finance\Application\UseCases\TransferUserWalletBalanceHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WalletTransferController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveWalletTransferRecipient $recipients,
        RubleAmountParser $amounts,
        TransferUserWalletBalanceHandler $transfers,
        CreateUserNotificationHandler $notifications,
    ): RedirectResponse {
        $validated = $request->validate([
            'recipient' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        /** @var User|null $sender */
        $sender = $request->user();
        abort_if($sender === null, 401);
        $sender = $sender->canonical();

        try {
            $recipient = $recipients->handle((string) $validated['recipient']);
            $amountMinor = $amounts->parse((string) $validated['amount']);
            $result = $transfers->handle(
                sender: $sender,
                recipient: $recipient,
                amountMinor: $amountMinor,
                balanceType: WalletBalanceTypeEnum::BONUS,
                idempotencyKey: (string) $validated['idempotency_key'],
            );
        } catch (WalletException $e) {
            return back()
                ->withInput($request->except('idempotency_key'))
                ->withErrors(['transfer' => $e->getMessage()]);
        }

        if (! $result->replayed) {
            $senderHandle = $this->handleFor($sender);
            $notifications->handle(new CreateUserNotificationDTO(
                userId: (int) $recipient->id,
                type: UserNotificationTypeEnum::FINANCE,
                title: 'Получен перевод',
                body: $senderHandle.' перевёл вам '.$this->formatMinor($amountMinor).' бонусами.',
                actionUrl: route('account.wallet', [], false),
                actionText: 'Открыть кошелёк',
                payload: [
                    'source' => 'finance.wallet.transfer.received',
                    'wallet_operation_id' => (int) $result->operation->id,
                    'sender_user_id' => (int) $sender->id,
                    'amount_minor' => $amountMinor,
                    'balance_type' => WalletBalanceTypeEnum::BONUS->value,
                ],
            ));
        }

        return redirect()
            ->route('account.wallet')
            ->with('status', 'Перевод '.$this->formatMinor($amountMinor).' для '.$this->handleFor($recipient).' выполнен.');
    }

    private function handleFor(User $user): string
    {
        $handle = trim((string) ($user->nickname ?: $user->username));

        return $handle !== '' ? '@'.$handle : 'пользователя #'.$user->id;
    }

    private function formatMinor(int $amountMinor): string
    {
        $rubles = intdiv($amountMinor, 100);
        $kopeks = $amountMinor % 100;

        return number_format($rubles, 0, ',', ' ').','.str_pad((string) $kopeks, 2, '0', STR_PAD_LEFT).' ₽';
    }
}
