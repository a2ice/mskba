<?php

namespace App\Modules\Finance\Application\UseCases;

use App\Modules\Finance\Application\DTO\WalletTransferResult;
use App\Modules\Finance\Application\Services\WalletOwnerOperationsPolicy;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Finance\Domain\Exceptions\WalletIdempotencyException;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class TransferUserWalletBalanceHandler
{
    public function __construct(
        private EnsureWalletHandler $wallets,
        private WalletOwnerOperationsPolicy $ownerOperations,
    ) {}

    public function handle(
        User $sender,
        User $recipient,
        int $amountMinor,
        WalletBalanceTypeEnum $balanceType,
        string $idempotencyKey,
    ): WalletTransferResult {
        $sender = $sender->canonical();
        $recipient = $recipient->canonical();

        if (! $sender->isConfirmed() || ! $recipient->isConfirmed()) {
            throw new WalletException('Переводы доступны только между подтверждёнными аккаунтами.');
        }

        if ((int) $sender->id === (int) $recipient->id) {
            throw new WalletException('Нельзя перевести средства самому себе.');
        }

        if ($amountMinor <= 0) {
            throw new WalletException('Сумма перевода должна быть больше нуля.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new WalletException('Некорректный ключ операции.');
        }

        $enabled = match ($balanceType) {
            WalletBalanceTypeEnum::BONUS => (bool) config('finance.user_transfers.bonus_enabled', true),
            WalletBalanceTypeEnum::REAL => (bool) config('finance.user_transfers.real_enabled', false),
        };

        if (! $enabled) {
            throw new WalletException(
                $balanceType === WalletBalanceTypeEnum::REAL
                    ? 'Переводы основного баланса пока отключены.'
                    : 'Переводы бонусного баланса временно отключены.',
            );
        }

        $this->ownerOperations->ensureEnabled(WalletOwnerTypeEnum::USER);

        $sourceWallet = $this->wallets->handle(WalletOwnerTypeEnum::USER, (int) $sender->id);
        $destinationWallet = $this->wallets->handle(WalletOwnerTypeEnum::USER, (int) $recipient->id);

        $requestHash = hash('sha256', json_encode([
            'operation_type' => WalletOperationTypeEnum::USER_TRANSFER->value,
            'source_wallet_id' => (int) $sourceWallet->id,
            'destination_wallet_id' => (int) $destinationWallet->id,
            'balance_type' => $balanceType->value,
            'amount_minor' => $amountMinor,
        ], JSON_THROW_ON_ERROR));

        $senderHandle = $this->handleFor($sender);
        $recipientHandle = $this->handleFor($recipient);

        return DB::transaction(function () use (
            $sourceWallet,
            $destinationWallet,
            $sender,
            $recipient,
            $amountMinor,
            $balanceType,
            $idempotencyKey,
            $requestHash,
            $senderHandle,
            $recipientHandle,
        ): WalletTransferResult {
            $lockedWallets = Wallet::query()
                ->whereIn('id', [$sourceWallet->id, $destinationWallet->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedSource = $lockedWallets->get($sourceWallet->id);
            $lockedDestination = $lockedWallets->get($destinationWallet->id);

            if (! $lockedSource || ! $lockedDestination) {
                throw new WalletException('Не удалось заблокировать кошельки для перевода.');
            }

            $now = now();

            WalletOperation::query()->insertOrIgnore([
                'type' => WalletOperationTypeEnum::USER_TRANSFER->value,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'performed_by_user_id' => $sender->id,
                'reference_type' => 'user_transfer',
                'reference_key' => (string) $recipient->id,
                'metadata' => json_encode([
                    'sender_user_id' => (int) $sender->id,
                    'recipient_user_id' => (int) $recipient->id,
                    'sender_handle' => $senderHandle,
                    'recipient_handle' => $recipientHandle,
                    'balance_type' => $balanceType->value,
                ], JSON_THROW_ON_ERROR),
                'status' => WalletOperationStatusEnum::PROCESSING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $operation = WalletOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($operation->type !== WalletOperationTypeEnum::USER_TRANSFER || $operation->request_hash !== $requestHash) {
                throw new WalletIdempotencyException;
            }

            if ($operation->status === WalletOperationStatusEnum::COMPLETED) {
                return new WalletTransferResult($operation->load('entries'), true);
            }

            if ($operation->status !== WalletOperationStatusEnum::PROCESSING) {
                throw new WalletException('Финансовая операция временно недоступна.');
            }

            $field = match ($balanceType) {
                WalletBalanceTypeEnum::REAL => 'real_balance_minor',
                WalletBalanceTypeEnum::BONUS => 'bonus_balance_minor',
            };

            $sourceCurrent = (int) $lockedSource->{$field};
            $destinationCurrent = (int) $lockedDestination->{$field};

            if ($sourceCurrent < $amountMinor) {
                throw new InsufficientWalletBalanceException;
            }

            if ($amountMinor > PHP_INT_MAX - $destinationCurrent) {
                throw new WalletException('Баланс получателя выходит за допустимый диапазон.');
            }

            $sourceNext = $sourceCurrent - $amountMinor;
            $destinationNext = $destinationCurrent + $amountMinor;

            $lockedSource->forceFill([$field => $sourceNext])->save();
            $lockedDestination->forceFill([$field => $destinationNext])->save();

            $operation->entries()->create([
                'wallet_id' => $lockedSource->id,
                'balance_type' => $balanceType,
                'amount_minor' => -$amountMinor,
                'balance_after_minor' => $sourceNext,
            ]);
            $operation->entries()->create([
                'wallet_id' => $lockedDestination->id,
                'balance_type' => $balanceType,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $destinationNext,
            ]);

            $operation->forceFill([
                'status' => WalletOperationStatusEnum::COMPLETED,
                'completed_at' => now(),
            ])->save();

            return new WalletTransferResult($operation->fresh('entries'), false);
        });
    }

    private function handleFor(User $user): string
    {
        $handle = trim((string) ($user->nickname ?: $user->username));

        return $handle !== '' ? '@'.$handle : 'Пользователь #'.$user->id;
    }
}
