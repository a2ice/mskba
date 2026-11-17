<?php

namespace App\Modules\Dashboard\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccountDashboardWidgetPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(AccountDashboardWidget::class, 'widget_id');
    }
}
