<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\Services\ResolveWalletTransferRecipient;
use App\Modules\Finance\Application\Services\RubleAmountParser;
use App\Modules\Finance\Application\UseCases\TransferUserWalletBalanceHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WalletTransferController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveWalletTransferRecipient $recipients,
        RubleAmountParser $amounts,
        TransferUserWalletBalanceHandler $transfers,
    ): RedirectResponse {
        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        /** @var User|null $sender */
        $sender = $request->user();
        abort_if($sender === null, 401);
        $sender = $sender->canonical();

        try {
            $recipient = $recipients->handle($sender, (int) $validated['recipient_user_id']);
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

        $response = redirect()
            ->route('account.wallet')
            ->with('status', 'Перевод '.$this->formatMinor($amountMinor).' для '.$this->handleFor($recipient).' выполнен.');

        if ($result->notificationFailed) {
            $response->with('warning', 'Перевод выполнен, но уведомление получателю не удалось создать. Повторно переводить сумму не нужно.');
        }

        return $response;
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
