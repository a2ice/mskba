<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'permission',
    'is_allowed',
])]
final class UserOperationalPermission extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'permission' => UserOperationalPermissionEnum::class,
            'is_allowed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
