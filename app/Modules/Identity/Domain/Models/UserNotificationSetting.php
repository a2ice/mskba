<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'messenger_notifications'])]
final class UserNotificationSetting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
