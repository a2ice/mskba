<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class OperatorConversation extends Model
{
    protected $guarded = [];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OperatorMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(OperatorMessage::class)->latestOfMany();
    }
}
