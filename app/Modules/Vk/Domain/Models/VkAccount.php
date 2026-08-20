<?php

namespace App\Modules\Vk\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'vk_user_id', 'first_name', 'last_name', 'avatar_url', 'last_auth_at', 'raw_data'])]
final class VkAccount extends Model
{
    protected function casts(): array
    {
        return [
            'last_auth_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
