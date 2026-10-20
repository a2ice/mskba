<?php

namespace App\Presentation\Theming;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class DesignThemeCodeManager
{
    private function kind(string $kind): void
    {
        abort_unless(in_array($kind, ['css', 'js'], true), 404);
    }

    private function authorize(User $actor): void
    {
        $permissions = app(BackofficePermissionManager::class);
        abort_unless($permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $permissions->allows($actor, Permission::BACKOFFICE_THEME_MANAGE), 403);
    }

    private function validateSource(string $kind, string $source): void
    {
        $this->kind($kind);
        if (strlen($source) > 100_000) {
            throw ValidationException::withMessages([
                'source' => strtoupper($kind).' превышает лимит 100 КБ.',
            ]);
        }
        if ($kind === 'css') {
            // Reuse the editor's CSS safety grammar but NOT its Page Builder
            // @scope wrapper: theme CSS intentionally targets the full theme.
            app(PageBuilderMarkup::class)->css($source);
        }
    }

    public function asset(DesignTheme $theme, string $kind): ?DesignThemeCodeAsset
    {
        $this->kind($kind);
        if (! $theme->exists || ! Schema::hasTable('design_theme_code_assets')) {
            return null;
        }

        return $theme->codeAssets()->where('kind', $kind)->first();
    }

    public function activeSource(DesignTheme $theme, string $kind): ?string
    {
        $asset = $this->asset($theme, $kind);
        if (! $asset?->is_enabled || $asset->published_source === null) {
            return null;
        }

        return (string) $asset->published_source;
    }

    public function saveDraft(User $actor, DesignTheme $theme, string $kind, string $source): DesignThemeCodeAsset
    {
        $this->authorize($actor);
        $this->validateSource($kind, $source);

        return DB::transaction(function () use ($theme, $kind, $source): DesignThemeCodeAsset {
            $asset = $theme->codeAssets()->firstOrCreate(['kind' => $kind]);
            $asset->draft_source = $source;
            $asset->save();

            return $asset->refresh();
        });
    }

    public function saveAndPublish(User $actor, DesignTheme $theme, string $kind, string $source): DesignThemeCodeAsset
    {
        $this->authorize($actor);
        $this->validateSource($kind, $source);
        if ($kind === 'js' && trim($source) === '') {
            throw ValidationException::withMessages(['source' => 'Для отключения JS используй кнопку «Отключить JS».']);
        }

        return DB::transaction(function () use ($actor, $theme, $kind, $source): DesignThemeCodeAsset {
            $asset = $theme->codeAssets()->firstOrCreate(['kind' => $kind]);
            $asset = DesignThemeCodeAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $hash = hash('sha256', $source);
            $enabled = trim($source) !== '';
            if ($asset->published_hash === $hash && (bool) $asset->is_enabled === $enabled) {
                $asset->draft_source = $source;
                $asset->save();

                return $asset->refresh();
            }
            $asset->draft_source = $source;
            $asset->published_source = $source;
            $asset->published_hash = $hash;
            $asset->published_at = now();
            $asset->is_enabled = $enabled;
            $asset->save();
            $this->snapshot($asset, $actor, 'publish');

            return $asset->refresh();
        });
    }

    public function disable(User $actor, DesignTheme $theme, string $kind): DesignThemeCodeAsset
    {
        $this->authorize($actor);
        $this->kind($kind);
        return DB::transaction(function () use ($actor, $theme, $kind): DesignThemeCodeAsset {
            $asset = $theme->codeAssets()->where('kind', $kind)->lockForUpdate()->firstOrFail();
            if ($asset->is_enabled) {
                $asset->is_enabled = false;
                $asset->save();
                $this->snapshot($asset, $actor, 'disable');
            }
            return $asset->refresh();
        });
    }

    public function restore(User $actor, DesignTheme $theme, string $kind, int $revisionId): DesignThemeCodeAsset
    {
        $this->authorize($actor);
        $this->kind($kind);
        return DB::transaction(function () use ($actor, $theme, $kind, $revisionId): DesignThemeCodeAsset {
            $asset = $theme->codeAssets()->where('kind', $kind)->lockForUpdate()->firstOrFail();
            $revision = $asset->revisions()->whereKey($revisionId)->firstOrFail();
            $source = (string) $revision->source;
            $this->validateSource($kind, $source);
            $asset->draft_source = $source;
            $asset->published_source = $source;
            $asset->published_hash = hash('sha256', $source);
            $asset->is_enabled = (bool) $revision->is_enabled;
            $asset->published_at = now();
            $asset->save();
            $this->snapshot($asset, $actor, 'restore');

            return $asset->refresh();
        });
    }

    private function snapshot(DesignThemeCodeAsset $asset, User $actor, string $action): void
    {
        $asset->revisions()->create([
            'action' => $action,
            'source' => $asset->published_source,
            'content_hash' => $asset->published_hash,
            'is_enabled' => $asset->is_enabled,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
