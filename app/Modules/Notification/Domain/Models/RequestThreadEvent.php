<?php

namespace App\Modules\Notification\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class RequestThreadEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['request_thread_id', 'actor_user_id', 'target_user_id', 'action', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
