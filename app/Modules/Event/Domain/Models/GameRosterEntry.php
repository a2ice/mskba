<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'game_side_id',
    'user_id',
    'source_contract_membership_id',
    'source_event_participant_id',
    'status',
])]
class GameRosterEntry extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function gameSide(): BelongsTo
    {
        return $this->belongsTo(GameSide::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceContractMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'source_contract_membership_id');
    }

    public function sourceEventParticipant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'source_event_participant_id');
    }

    protected function casts(): array
    {
        return ['status' => GameRosterStatusEnum::class];
    }
}
