<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DirectMessage extends Model
{
    protected $guarded = [];
    public function conversation(): BelongsTo { return $this->belongsTo(DirectConversation::class, 'direct_conversation_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
}
