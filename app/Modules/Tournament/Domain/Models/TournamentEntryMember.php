<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tournament_entry_id', 'user_id', 'source_contract_membership_id', 'position'])]
class TournamentEntryMember extends Model
{
    use Auditable;

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'tournament_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceContractMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'source_contract_membership_id');
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
