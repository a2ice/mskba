<?php

namespace App\Presentation\Theming;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DesignThemeManager
{
    public function default(): DesignTheme
    {
        try {
            $id = DB::table('design_theme_settings')->where('id', 1)->value('theme_id');

            return DesignTheme::query()->whereKey($id)->where('is_published', true)->first()
                ?? DesignTheme::query()->where('slug', 'arena_night')->first()
                ?? $this->builtInNight();
        } catch (QueryException $exception) {
            // Public pages must render before the new migration exists (e.g.
            // CLI tests without RefreshDatabase and zero-downtime upgrades).
            // Never silently mask unrelated database failures.
            if (! Schema::hasTable('design_theme_settings') || ! Schema::hasTable('design_themes')) {
                return $this->builtInNight();
            }

            throw $exception;
        }
    }

    private function builtInNight(): DesignTheme
    {
        return new DesignTheme([
            ...config('design_palettes.night'),
            'is_system' => true,
            'is_published' => true,
        ]);
    }

    public function forUser(?User $user): DesignTheme
    {
        if ($user && $user->design_theme_id) {
            $selected = DesignTheme::query()->whereKey($user->design_theme_id)
                ->where('is_published', true)->first();
            if ($selected) {
                return $selected;
            }
        }

        return $this->default();
    }

    public function current(): DesignTheme
    {
        /** @var User|null $user */
        $user = auth('web')->user();

        return $this->forUser($user?->canonical());
    }

    public function cssUrl(DesignTheme $theme): string
    {
        return route('design-themes.css', [
            'theme' => $theme->slug, 'revision' => app(DesignThemeCss::class)->revision($theme),
        ]);
    }

    public function options(): array
    {
        return DesignTheme::query()->where('is_published', true)
            ->orderBy('is_system', 'desc')->orderBy('name')
            ->get(['id', 'name', 'slug', 'mode'])
            ->mapWithKeys(fn (DesignTheme $item): array => [$item->id => $item->name])
            ->all();
    }

    public function selectDefault(DesignTheme $theme): void
    {
        abort_unless($theme->is_published, 422, 'Нельзя сделать черновую тему общей.');

        DB::table('design_theme_settings')->where('id', 1)->update([
            'theme_id' => $theme->getKey(), 'updated_at' => now(),
        ]);
    }
}
