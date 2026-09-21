<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'owner_type',
    'owner_id',
    'type',
    'currency',
])]
class Wallet extends Model
{
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function totalBalanceMinor(): int
    {
        return (int) $this->real_balance_minor + (int) $this->bonus_balance_minor;
    }

    public function balanceMinor(WalletBalanceTypeEnum $type): int
    {
        return match ($type) {
            WalletBalanceTypeEnum::REAL => (int) $this->real_balance_minor,
            WalletBalanceTypeEnum::BONUS => (int) $this->bonus_balance_minor,
        };
    }

    protected function casts(): array
    {
        return [
            'owner_type' => WalletOwnerTypeEnum::class,
            'owner_id' => 'integer',
            'type' => WalletTypeEnum::class,
            'real_balance_minor' => 'integer',
            'bonus_balance_minor' => 'integer',
        ];
    }
}
