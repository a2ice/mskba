<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'venue_court_id', 'mode_override', 'value_override', 'currency', 'disable_all_commissions_override', 'version', 'updated_by_user_id'])]
final class VenueCourtCommissionPolicy extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    protected function casts(): array
    {
        return ['value_override' => 'integer', 'disable_all_commissions_override' => 'boolean', 'version' => 'integer'];
    }
}
