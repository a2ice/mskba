<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_join_request_id', 'sender_user_id', 'body'])]
final class TeamJoinRequestMessage extends Model
{
    public function joinRequest(): BelongsTo
    {
        return $this->belongsTo(TeamJoinRequest::class, 'team_join_request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
