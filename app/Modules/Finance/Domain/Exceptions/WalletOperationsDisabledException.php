<?php

namespace App\Modules\Finance\Domain\Exceptions;

use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;

final class WalletOperationsDisabledException extends WalletException
{
    public function __construct(WalletOwnerTypeEnum $ownerType)
    {
        parent::__construct("Операции кошельков типа «{$ownerType->label()}» пока отключены.");
    }
}
