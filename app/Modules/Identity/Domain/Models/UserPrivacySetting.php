<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'type',
    'visibility',
])]
final class UserPrivacySetting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => UserPrivacySettingTypeEnum::class,
            'visibility' => UserPrivacyVisibilityEnum::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_privacy_setting_allowed_users',
            'privacy_setting_id',
            'allowed_user_id',
        )->withTimestamps();
    }
}
