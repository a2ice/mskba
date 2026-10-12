<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DirectConversation extends Model
{
    protected $guarded = [];
    public function lowUser(): BelongsTo { return $this->belongsTo(User::class, 'user_low_id'); }
    public function highUser(): BelongsTo { return $this->belongsTo(User::class, 'user_high_id'); }
    public function messages(): HasMany { return $this->hasMany(DirectMessage::class); }
    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DirectMessage::class)->latestOfMany();
    }
}
