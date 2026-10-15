<?php

namespace App\Presentation\Theming;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DesignTheme extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['colors' => 'array', 'is_system' => 'boolean', 'is_published' => 'boolean'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DesignThemeRevision::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $theme): void {
            $theme->is_system = false;
        });
        self::updating(function (self $theme): void {
            // A DB-level system theme can never be converted into a disposable one, or vice versa.
            $theme->is_system = (bool) $theme->getOriginal('is_system');
            $theme->slug = $theme->getOriginal('slug');

            if ($theme->is_system) {
                $theme->mode = $theme->getOriginal('mode');
                $theme->is_published = true;
            }
            if (! $theme->is_published && DB::table('design_theme_settings')
                ->where('theme_id', $theme->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'is_published' => 'Сначала выбери другую общую тему.',
                ]);
            }
        });
        self::saved(function (self $theme): void {
            if (! $theme->wasRecentlyCreated && ! $theme->wasChanged([
                'name', 'description', 'colors', 'css_code', 'mode', 'is_published',
                'body_font', 'heading_font', 'widget_glow_color', 'widget_glow_strength',
                'sidebar_background', 'sidebar_text', 'sidebar_hover',
                'sidebar_active_background', 'sidebar_active_text',
            ])) {
                return;
            }

            $theme->revisions()->create([
                'name' => $theme->name,
                'description' => $theme->description,
                'mode' => $theme->mode,
                'colors' => $theme->colors,
                'css_code' => $theme->css_code,
                'is_published' => $theme->is_published,
                'body_font' => $theme->body_font,
                'heading_font' => $theme->heading_font,
                'widget_glow_color' => $theme->widget_glow_color,
                'widget_glow_strength' => $theme->widget_glow_strength,
                'sidebar_background' => $theme->sidebar_background,
                'sidebar_text' => $theme->sidebar_text,
                'sidebar_hover' => $theme->sidebar_hover,
                'sidebar_active_background' => $theme->sidebar_active_background,
                'sidebar_active_text' => $theme->sidebar_active_text,
                'created_at' => now(),
            ]);
        });
        self::deleting(function (self $theme): void {
            if ($theme->is_system || DB::table('design_theme_settings')
                ->where('theme_id', $theme->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'theme' => 'Системную или используемую по умолчанию тему удалить нельзя.',
                ]);
            }
        });
    }
}
