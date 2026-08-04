<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_sport_profile_id', 'contract_membership_id', 'assignment', 'position'])]
class TeamSportLineupMember extends Model
{
    public function sportProfile(): BelongsTo
    {
        return $this->belongsTo(TeamSportProfile::class, 'team_sport_profile_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'contract_membership_id');
    }

    protected function casts(): array
    {
        return ['assignment' => TeamLineupAssignmentEnum::class];
    }
}
