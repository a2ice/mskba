<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class SuperadminWalletBonusGrant
{
    public function __construct(
        private EnsureWalletHandler $wallets,
        private CreditWalletHandler $credits,
    ) {}

    public function allowed(User $user): bool
    {
        $user = $user->canonical();

        return $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    public function grant(
        User $user,
        string $currentPassword,
        int $amountMinor,
        string $idempotencyKey,
    ): WalletOperation {
        $user = $user->canonical();

        if (! $this->allowed($user)) {
            throw new WalletException('Начислять бонусы может только подтверждённый superadmin.');
        }

        if (! $this->passwordMatchesIdentity($user, $currentPassword)) {
            throw new WalletException('Текущий пароль указан неверно.');
        }

        $wallet = $this->wallets->handle(WalletOwnerTypeEnum::USER, (int) $user->id);

        return $this->credits->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: $amountMinor,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: $idempotencyKey,
            performedByUserId: (int) $user->id,
            referenceType: 'superadmin_bonus_grant',
            referenceKey: (string) $user->id,
            metadata: [
                'description' => 'Начисление superadmin',
                'source' => 'superadmin_manual_bonus_grant',
            ],
        );
    }

    private function passwordMatchesIdentity(User $user, string $currentPassword): bool
    {
        if ($currentPassword === '') {
            return false;
        }

        return User::query()
            ->whereIn('id', $user->identityIds())
            ->whereNotNull('password')
            ->get()
            ->contains(
                fn (User $identityUser): bool => is_string($identityUser->password)
                    && Hash::check($currentPassword, $identityUser->password),
            );
    }
}
