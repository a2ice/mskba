<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'user_id',
    'role',
    'status',
    'joined_at',
    'left_at',
])]
class EventParticipant extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'role' => EventParticipantRoleEnum::class,
            'status' => EventParticipantStatusEnum::class,
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }
}
