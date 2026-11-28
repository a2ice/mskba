<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class GameInvitationThread extends Model
{
    protected $fillable = ['game_id', 'team_id'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class)->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    public function thread(): HasOne
    {
        return $this->hasOne(RequestThread::class, 'subject_id')->where('type', 'game_invitation');
    }
}
