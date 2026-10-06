<?php

namespace App\Modules\Rewards\Domain\Models;

use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Domain\Enums\RewardGrantStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reward_id',
    'reward_version_id',
    'recipient_user_id',
    'mechanism_code',
    'wallet_operation_type',
    'business_fact_type',
    'business_fact_key',
    'amount_minor',
    'currency',
    'status',
    'wallet_operation_id',
    'completed_at',
])]
final class RewardGrant extends Model
{
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RewardVersion::class, 'reward_version_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function walletOperation(): BelongsTo
    {
        return $this->belongsTo(WalletOperation::class, 'wallet_operation_id');
    }

    protected function casts(): array
    {
        return [
            'reward_id' => 'integer',
            'reward_version_id' => 'integer',
            'recipient_user_id' => 'integer',
            'amount_minor' => 'integer',
            'wallet_operation_type' => WalletOperationTypeEnum::class,
            'status' => RewardGrantStatusEnum::class,
            'wallet_operation_id' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
