<?php

namespace App\Modules\Finance\Application\DTO;

use App\Modules\Finance\Domain\Models\WalletOperation;

final readonly class WalletTransferResult
{
    public function __construct(
        public WalletOperation $operation,
        public bool $replayed,
    ) {}
}
