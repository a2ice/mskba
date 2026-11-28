<?php

namespace App\Modules\Event\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class GameInvitationRecipient extends Model
{
    protected $fillable = ['game_admission_id', 'user_id', 'notified_by_user_id', 'comment'];
}
