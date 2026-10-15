<?php

namespace App\Presentation\Theming;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Support\Facades\DB;

final class DesignThemeRevisionManager
{
    public function restore(DesignTheme $theme, int $revisionId): void
    {
        $actor = auth('web')->user();
        abort_unless($actor && app(BackofficePermissionManager::class)
            ->allows($actor, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)
                ->allows($actor, Permission::BACKOFFICE_THEME_MANAGE), 403);

        DB::transaction(function () use ($theme, $revisionId): void {
            $record = DesignTheme::query()->lockForUpdate()->findOrFail($theme->getKey());
            $revision = $record->revisions()->whereKey($revisionId)->firstOrFail();

            app(DesignThemeCss::class)->validate($revision->colors, $revision->css_code);

            $record->fill([
                'name' => $revision->name,
                'description' => $revision->description,
                'mode' => $revision->mode,
                'colors' => $revision->colors,
                'css_code' => $revision->css_code,
                'is_published' => $revision->is_published,
                'body_font' => $revision->body_font,
                'heading_font' => $revision->heading_font,
                'widget_glow_color' => $revision->widget_glow_color,
                'widget_glow_strength' => $revision->widget_glow_strength,
            ])->save();
            // The saved-event appends a new version so the restore itself is reversible.
        });
    }
}
