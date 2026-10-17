<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'entity_type', 'entity_id', 'draft_js', 'published_js', 'previous_js',
    'published_hash', 'previous_hash', 'is_enabled', 'published_at',
])]
final class PageBuilderScriptAsset extends Model
{
    protected $table = 'page_builder_script_assets';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
