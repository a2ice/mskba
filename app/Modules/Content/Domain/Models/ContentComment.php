<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['content_item_id', 'user_id', 'body', 'is_published', 'published_at', 'revision'])]
final class ContentComment extends Model
{
    use Auditable, SoftDeletes;

    protected $attributes = ['is_published' => false, 'revision' => 1];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime', 'revision' => 'integer'];
    }
}
