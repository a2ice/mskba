<?php

namespace App\Modules\Finance\Domain\Exceptions;

final class InsufficientWalletBalanceException extends WalletException
{
    public function __construct()
    {
        parent::__construct('Недостаточно средств для операции.');
    }
}
