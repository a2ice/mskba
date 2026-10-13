<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RequestThreadMessage extends Model
{
    protected $fillable = ['request_thread_id', 'sender_user_id', 'body'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
