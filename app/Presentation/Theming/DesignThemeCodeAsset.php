<?php

namespace App\Presentation\Theming;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DesignThemeCodeAsset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(DesignTheme::class, 'design_theme_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DesignThemeCodeRevision::class, 'design_theme_code_asset_id');
    }
}
