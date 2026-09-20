<?php

namespace App\Modules\Finance\Application\UseCases;

use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;

final readonly class EnsureWalletHandler
{
    public function __construct(private WalletOwnerResolver $owners) {}

    public function handle(
        WalletOwnerTypeEnum $ownerType,
        int $ownerId,
        WalletTypeEnum $walletType = WalletTypeEnum::MAIN,
        string $currency = 'RUB',
    ): Wallet {
        $canonicalOwnerId = $this->owners->canonicalOwnerId($ownerType, $ownerId);
        $currency = strtoupper(trim($currency));

        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Валюта кошелька должна быть задана кодом ISO 4217.');
        }

        return Wallet::query()->firstOrCreate(
            [
                'owner_type' => $ownerType->value,
                'owner_id' => $canonicalOwnerId,
                'type' => $walletType->value,
                'currency' => $currency,
            ],
            [
                'real_balance_minor' => 0,
                'bonus_balance_minor' => 0,
            ],
        );
    }
}
