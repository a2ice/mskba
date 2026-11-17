<?php

namespace App\Modules\Content\Domain\Models;

use App\Presentation\Theming\DesignTheme;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'path', 'design_theme_id', 'name', 'meta_title', 'meta_description', 'template_id',
    'draft_html', 'draft_css',
])]
final class PageBuilderPage extends Model
{
    protected $table = 'page_builder_pages';

    public function designTheme(): BelongsTo
    {
        return $this->belongsTo(DesignTheme::class, 'design_theme_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PageBuilderTemplate::class, 'template_id');
    }

    public function publishedTemplate(): BelongsTo
    {
        return $this->belongsTo(PageBuilderTemplate::class, 'published_template_id');
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'cms_override_enabled' => 'boolean',
            'has_previous_css' => 'boolean',
        ];
    }
}
