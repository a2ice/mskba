<?php

namespace App\Modules\Finance\Domain\Exceptions;

final class WalletIdempotencyException extends WalletException
{
    public function __construct()
    {
        parent::__construct('Ключ идемпотентности уже использован для другой финансовой операции.');
    }
}
