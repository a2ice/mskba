<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_item_id',
    'source_key',
    'pr_url',
    'task_reference',
    'documentation_paths',
    'source',
    'production_verified_at',
    'production_verified_by_user_id',
])]
final class ProjectUpdateDetail extends Model
{
    use Auditable;

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    protected function casts(): array
    {
        return [
            'documentation_paths' => 'array',
            'production_verified_at' => 'immutable_datetime',
        ];
    }
}
