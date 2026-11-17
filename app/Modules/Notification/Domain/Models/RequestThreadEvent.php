<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RequestThreadEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['request_thread_id', 'actor_user_id', 'target_user_id', 'action', 'metadata', 'created_at'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'metadata' => 'array'];
    }
}
