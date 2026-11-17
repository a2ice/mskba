<?php

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Presentation\Theming\DesignTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $theme = DesignTheme::query()->where('slug', 'mskba_classic')->first();
        if (! $theme) {
            // A different installer may not have created Classic yet.
            return;
        }

        foreach (['css', 'js'] as $kind) {
            $file = resource_path('themes/mskba_app/presets/mskba-classic.'.$kind);
            $source = file_get_contents($file);
            if (! is_string($source) || trim($source) === '') {
                throw new RuntimeException('Отсутствует исходный '.strtoupper($kind).' MSKBA Classic.');
            }
            if (strlen($source) > 100_000) {
                throw new RuntimeException('Превышен размер '.strtoupper($kind).' MSKBA Classic.');
            }
            if ($kind === 'css') {
                app(PageBuilderMarkup::class)->css($source);
            }

            DB::transaction(function () use ($theme, $kind, $source): void {
                $asset = DB::table('design_theme_code_assets')
                    ->where('design_theme_id', $theme->getKey())
                    ->where('kind', $kind)
                    ->lockForUpdate()->first();

                // Never overwrite changes made in Filament by an editor.
                if ($asset && ($asset->draft_source !== null || $asset->published_source !== null)) {
                    return;
                }

                $now = now();
                $hash = hash('sha256', $source);
                if ($asset) {
                    DB::table('design_theme_code_assets')->where('id', $asset->id)->update([
                        'draft_source' => $source,
                        'published_source' => $source,
                        'published_hash' => $hash,
                        'published_at' => $now,
                        'is_enabled' => true,
                        'updated_at' => $now,
                    ]);
                    $assetId = $asset->id;
                } else {
                    $assetId = DB::table('design_theme_code_assets')->insertGetId([
                        'design_theme_id' => $theme->getKey(),
                        'kind' => $kind,
                        'draft_source' => $source,
                        'published_source' => $source,
                        'published_hash' => $hash,
                        'published_at' => $now,
                        'is_enabled' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('design_theme_code_revisions')->insert([
                    'design_theme_code_asset_id' => $assetId,
                    'action' => 'publish',
                    'source' => $source,
                    'content_hash' => $hash,
                    'is_enabled' => true,
                    'actor_id' => null,
                    'created_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        // Deliberate no-op: published theme assets may have editor changes.
    }
};
