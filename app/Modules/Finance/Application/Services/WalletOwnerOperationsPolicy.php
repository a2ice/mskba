<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletOperationsDisabledException;
use Illuminate\Contracts\Config\Repository;

final readonly class WalletOwnerOperationsPolicy
{
    public function __construct(private Repository $config) {}

    public function enabled(WalletOwnerTypeEnum $ownerType): bool
    {
        return (bool) $this->config->get("finance.wallet_owner_operations.{$ownerType->value}", false);
    }

    public function ensureEnabled(WalletOwnerTypeEnum $ownerType): void
    {
        if (! $this->enabled($ownerType)) {
            throw new WalletOperationsDisabledException($ownerType);
        }
    }
}
