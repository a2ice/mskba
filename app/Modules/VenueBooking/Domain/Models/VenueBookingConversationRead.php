<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'user_id', 'last_read_message_id', 'read_at'])]
class VenueBookingConversationRead extends Model
{
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(VenueBookingConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
