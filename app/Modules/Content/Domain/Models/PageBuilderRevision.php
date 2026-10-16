<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['entity_type', 'entity_id', 'action', 'snapshot', 'user_id', 'created_at'])]
final class PageBuilderRevision extends Model
{
    protected $table = 'page_builder_revisions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
