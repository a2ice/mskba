<?php

namespace App\Modules\Finance\Application\UseCases;

use App\Modules\Finance\Application\Services\IdempotentWalletMutation;
use App\Modules\Finance\Application\Services\WalletOwnerOperationsPolicy;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use InvalidArgumentException;

final readonly class CreditWalletHandler
{
    public function __construct(
        private IdempotentWalletMutation $mutations,
        private WalletOwnerOperationsPolicy $ownerOperations,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function handle(
        Wallet $wallet,
        WalletBalanceTypeEnum $balanceType,
        int $amountMinor,
        WalletOperationTypeEnum $operationType,
        string $idempotencyKey,
        ?int $performedByUserId = null,
        ?string $referenceType = null,
        ?string $referenceKey = null,
        array $metadata = [],
    ): WalletOperation {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Сумма начисления должна быть положительной.');
        }

        if (! $operationType->supportsCredit()) {
            throw new InvalidArgumentException('Тип операции не поддерживает начисление.');
        }

        if ($operationType === WalletOperationTypeEnum::TOP_UP && $balanceType !== WalletBalanceTypeEnum::REAL) {
            throw new InvalidArgumentException('Пополнение реальными деньгами зачисляется только в real-баланс.');
        }

        if ($operationType === WalletOperationTypeEnum::REFERRAL_REWARD && $balanceType !== WalletBalanceTypeEnum::BONUS) {
            throw new InvalidArgumentException('Реферальное вознаграждение зачисляется только в bonus-баланс.');
        }

        if ($operationType === WalletOperationTypeEnum::BONUS_GRANT && $balanceType !== WalletBalanceTypeEnum::BONUS) {
            throw new InvalidArgumentException('Бонусное начисление зачисляется только в bonus-баланс.');
        }

        $this->ownerOperations->ensureEnabled($wallet->owner_type);

        return $this->mutations->execute(
            wallet: $wallet,
            operationType: $operationType,
            idempotencyKey: $idempotencyKey,
            payload: [
                'balance_type' => $balanceType,
                'amount_minor' => $amountMinor,
            ],
            callback: function (Wallet $lockedWallet, WalletOperation $operation) use ($balanceType, $amountMinor): void {
                $field = match ($balanceType) {
                    WalletBalanceTypeEnum::REAL => 'real_balance_minor',
                    WalletBalanceTypeEnum::BONUS => 'bonus_balance_minor',
                };
                $current = (int) $lockedWallet->{$field};
                if ($amountMinor > PHP_INT_MAX - $current) {
                    throw new InvalidArgumentException('Сумма начисления выходит за допустимый диапазон.');
                }
                $next = $current + $amountMinor;

                $lockedWallet->forceFill([$field => $next])->save();
                $operation->entries()->create([
                    'wallet_id' => $lockedWallet->id,
                    'balance_type' => $balanceType,
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $next,
                ]);
            },
            performedByUserId: $performedByUserId,
            referenceType: $referenceType,
            referenceKey: $referenceKey,
            metadata: $metadata,
        );
    }
}
