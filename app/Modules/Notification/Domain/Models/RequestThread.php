<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RequestThread extends Model
{
    protected $fillable = ['type', 'subject_id', 'lead_user_id'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(RequestThreadMembership::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RequestThreadMessage::class);
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RequestThreadMessage::class)->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequestThreadEvent::class);
    }
}
