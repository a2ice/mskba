<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'name', 'description', 'draft_html', 'draft_css'])]
final class PageBuilderComponent extends Model
{
    protected $table = 'page_builder_components';

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
