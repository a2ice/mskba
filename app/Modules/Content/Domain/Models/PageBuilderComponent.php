<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'name', 'description', 'draft_html', 'draft_css', 'draft_slides', 'draft_options'])]
final class PageBuilderComponent extends Model
{
    protected $table = 'page_builder_components';

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'has_previous_css' => 'boolean', 'draft_slides' => 'array', 'published_slides' => 'array',
            'draft_options' => 'array', 'published_options' => 'array'];
    }
}
