<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['training_session_id', 'contract_membership_id', 'user_id'])]
class TrainingSessionCoach extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function contractMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
