<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sports_section_id', 'name', 'amount_minor', 'currency', 'sessions_count', 'duration_days', 'is_active'])]
class SectionPricingPlan extends Model
{
    use Auditable;

    public function section(): BelongsTo
    {
        return $this->belongsTo(SportsSection::class, 'sports_section_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'sessions_count' => 'integer',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
