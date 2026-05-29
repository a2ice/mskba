<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\ContractSubjectTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'subject_type',
    'subject_id',
    'permission',
    'status',
    'starts_at',
    'expires_at',
])]
class Contract extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => ContractSubjectTypeEnum::class,
            'status' => ContractStatusEnum::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
