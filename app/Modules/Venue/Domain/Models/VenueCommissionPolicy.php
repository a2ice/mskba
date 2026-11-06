<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'mode_override', 'value_override', 'currency', 'selected_agent_id', 'version', 'updated_by_user_id'])]
final class VenueCommissionPolicy extends Model
{
    use Auditable;

    public function selectedAgent(): BelongsTo
    {
        return $this->belongsTo(VenueCommissionAgent::class, 'selected_agent_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    protected function casts(): array
    {
        return ['value_override' => 'integer', 'version' => 'integer'];
    }
}
