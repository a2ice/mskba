<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'name', 'draft_html', 'draft_css'])]
final class PageBuilderTemplate extends Model
{
    protected $table = 'page_builder_templates';

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'has_previous_css' => 'boolean'];
    }
}
