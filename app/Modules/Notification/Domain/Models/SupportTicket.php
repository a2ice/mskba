<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'subject', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): HasOne
    {
        return $this->hasOne(RequestThread::class, 'subject_id')->where('type', 'support');
    }
}
