<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'wallet_id',
    'operation_id',
    'balance_type',
    'amount_minor',
    'balance_after_minor',
])]
class WalletLedgerEntry extends Model
{
    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Wallet ledger is immutable.'));
        static::deleting(static fn () => throw new LogicException('Wallet ledger is immutable.'));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WalletOperation::class, 'operation_id');
    }

    protected function casts(): array
    {
        return [
            'balance_type' => WalletBalanceTypeEnum::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
        ];
    }
}
