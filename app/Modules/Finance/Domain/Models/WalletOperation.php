<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'type',
    'idempotency_key',
    'request_hash',
    'performed_by_user_id',
    'reference_type',
    'reference_key',
    'metadata',
    'status',
    'completed_at',
])]
class WalletOperation extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if ($operation->getOriginal('status') === WalletOperationStatusEnum::COMPLETED->value) {
                throw new LogicException('Completed wallet operation is immutable.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Wallet operation history is immutable.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class, 'operation_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => WalletOperationTypeEnum::class,
            'metadata' => 'array',
            'status' => WalletOperationStatusEnum::class,
            'completed_at' => 'immutable_datetime',
        ];
    }
}
