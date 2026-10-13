<?php

namespace App\Modules\Notification\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RequestThreadMembership extends Model
{
    protected $fillable = ['request_thread_id', 'user_id', 'invited_by_user_id', 'joined_at', 'removed_at', 'removed_by_user_id', 'removal_reason'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(RequestThread::class, 'request_thread_id');
    }
}
