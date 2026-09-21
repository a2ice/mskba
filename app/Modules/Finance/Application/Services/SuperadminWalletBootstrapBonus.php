<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final readonly class SuperadminWalletBootstrapBonus
{
    public const AMOUNT_MINOR = 1_000_000;

    public function __construct(
        private EnsureWalletHandler $wallets,
        private CreditWalletHandler $credits,
    ) {}

    public function available(User $user): bool
    {
        $user = $user->canonical();

        if (! $user->isConfirmed() || ! $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            return false;
        }

        return ! WalletOperation::query()
            ->where('idempotency_key', $this->idempotencyKey($user))
            ->where('status', WalletOperationStatusEnum::COMPLETED->value)
            ->exists();
    }

    public function grant(User $user): WalletOperation
    {
        $user = $user->canonical();

        if (! $user->isConfirmed() || ! $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new WalletException('Тестовое начисление доступно только подтверждённому superadmin.');
        }

        $wallet = $this->wallets->handle(WalletOwnerTypeEnum::USER, (int) $user->id);

        return $this->credits->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: self::AMOUNT_MINOR,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: $this->idempotencyKey($user),
            performedByUserId: (int) $user->id,
            referenceType: 'finance_smoke',
            referenceKey: 'task218',
            metadata: [
                'description' => 'Тестовый бонус для проверки переводов',
                'source' => 'task218_wallet_transfer_smoke',
            ],
        );
    }

    private function idempotencyKey(User $user): string
    {
        return 'task218-superadmin-bonus-'.$user->id;
    }
}
