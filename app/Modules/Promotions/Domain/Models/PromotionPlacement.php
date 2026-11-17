<?php

namespace App\Modules\Promotions\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['promotion_id', 'placement_code', 'title_override', 'description_override'])]
final class PromotionPlacement extends Model
{
    use Auditable;

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
