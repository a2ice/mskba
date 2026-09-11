<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['training_session_id', 'section_trainee_membership_id', 'user_id', 'attendance_status'])]
class TrainingSessionParticipant extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function traineeMembership(): BelongsTo
    {
        return $this->belongsTo(SectionTraineeMembership::class, 'section_trainee_membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
