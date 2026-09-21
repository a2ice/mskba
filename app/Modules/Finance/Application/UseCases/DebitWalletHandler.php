<?php

namespace App\Modules\Finance\Application\UseCases;

use App\Modules\Finance\Application\Services\IdempotentWalletMutation;
use App\Modules\Finance\Application\Services\WalletOwnerOperationsPolicy;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletSpendingPolicyEnum;
use App\Modules\Finance\Domain\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use InvalidArgumentException;

final readonly class DebitWalletHandler
{
    public function __construct(
        private IdempotentWalletMutation $mutations,
        private WalletOwnerOperationsPolicy $ownerOperations,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function handle(
        Wallet $wallet,
        int $amountMinor,
        WalletOperationTypeEnum $operationType,
        string $idempotencyKey,
        WalletSpendingPolicyEnum $spendingPolicy = WalletSpendingPolicyEnum::BONUS_THEN_REAL,
        ?int $performedByUserId = null,
        ?string $referenceType = null,
        ?string $referenceKey = null,
        array $metadata = [],
    ): WalletOperation {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Сумма списания должна быть положительной.');
        }

        if (! $operationType->supportsDebit()) {
            throw new InvalidArgumentException('Тип операции не поддерживает списание.');
        }

        $this->ownerOperations->ensureEnabled($wallet->owner_type);

        return $this->mutations->execute(
            wallet: $wallet,
            operationType: $operationType,
            idempotencyKey: $idempotencyKey,
            payload: [
                'amount_minor' => $amountMinor,
                'spending_policy' => $spendingPolicy,
            ],
            callback: function (Wallet $lockedWallet, WalletOperation $operation) use ($amountMinor, $spendingPolicy): void {
                $real = (int) $lockedWallet->real_balance_minor;
                $bonus = (int) $lockedWallet->bonus_balance_minor;

                [$bonusDebit, $realDebit] = match ($spendingPolicy) {
                    WalletSpendingPolicyEnum::BONUS_THEN_REAL => $this->bonusThenReal($bonus, $real, $amountMinor),
                    WalletSpendingPolicyEnum::REAL_ONLY => $this->singleBalance($real, $amountMinor, false),
                    WalletSpendingPolicyEnum::BONUS_ONLY => $this->singleBalance($bonus, $amountMinor, true),
                };

                $nextBonus = $bonus - $bonusDebit;
                $nextReal = $real - $realDebit;

                $lockedWallet->forceFill([
                    'bonus_balance_minor' => $nextBonus,
                    'real_balance_minor' => $nextReal,
                ])->save();

                if ($bonusDebit > 0) {
                    $operation->entries()->create([
                        'wallet_id' => $lockedWallet->id,
                        'balance_type' => WalletBalanceTypeEnum::BONUS,
                        'amount_minor' => -$bonusDebit,
                        'balance_after_minor' => $nextBonus,
                    ]);
                }

                if ($realDebit > 0) {
                    $operation->entries()->create([
                        'wallet_id' => $lockedWallet->id,
                        'balance_type' => WalletBalanceTypeEnum::REAL,
                        'amount_minor' => -$realDebit,
                        'balance_after_minor' => $nextReal,
                    ]);
                }
            },
            performedByUserId: $performedByUserId,
            referenceType: $referenceType,
            referenceKey: $referenceKey,
            metadata: $metadata,
        );
    }

    /** @return array{int, int} */
    private function bonusThenReal(int $bonus, int $real, int $amountMinor): array
    {
        if ($bonus + $real < $amountMinor) {
            throw new InsufficientWalletBalanceException;
        }

        $bonusDebit = min($bonus, $amountMinor);

        return [$bonusDebit, $amountMinor - $bonusDebit];
    }

    /** @return array{int, int} */
    private function singleBalance(int $available, int $amountMinor, bool $bonus): array
    {
        if ($available < $amountMinor) {
            throw new InsufficientWalletBalanceException;
        }

        return $bonus ? [$amountMinor, 0] : [0, $amountMinor];
    }
}
