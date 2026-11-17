<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Transfer the legacy, Vite-bundled Hero Slider CSS into the editable CSS
 * resource owned by Page Builder's hero-slider component.
 *
 * Existing editor overrides are kept AFTER the baseline to preserve cascade.
 * A published component receives the same CSS in its published snapshot;
 * an unpublished one receives draft CSS only. Neither HTML nor slides change.
 */
return new class extends Migration
{
    private const MARKER = '/* MSKBA_HERO_SLIDER_BASE_CSS_TRANSFER_20261103 */';

    public function up(): void
    {
        $baseline = file_get_contents(database_path('seeders/page-builder/hero-slider.css'));

        if ($baseline === false || trim($baseline) === '') {
            throw new RuntimeException('Hero Slider baseline CSS is missing; refusing to remove live Vite styles.');
        }

        $baseCss = self::MARKER."\n".trim($baseline);

        DB::transaction(function () use ($baseCss): void {
            $component = DB::table('page_builder_components')
                ->where('slug', 'hero-slider')
                ->lockForUpdate()
                ->first();

            if ($component === null) {
                // A fresh installation may not have created the component yet.
                return;
            }

            $draft = $this->mergeCss($baseCss, $component->draft_css);
            $updates = ['draft_css' => $draft, 'updated_at' => now()];

            if ($component->published_at !== null) {
                $updates['published_css'] = $this->mergeCss($baseCss, $component->published_css);

                // The CSS rollback action must not restore an unstyled slider.
                // Preserve any already-authored previous version and its flag.
                if ($component->previous_css !== null) {
                    $updates['previous_css'] = $this->mergeCss($baseCss, $component->previous_css);
                }
            }

            DB::table('page_builder_components')->where('id', $component->id)->update($updates);
        });
    }

    private function mergeCss(string $baseCss, ?string $customCss): string
    {
        $customCss = trim((string) $customCss);

        if (str_contains($customCss, self::MARKER)) {
            return $customCss;
        }

        $combined = $baseCss.($customCss !== '' ? "\n\n/* Existing component CSS overrides */\n".$customCss : '');

        if (strlen($combined) > 100_000) {
            throw new RuntimeException('Hero Slider CSS exceeds the Page Builder CSS editor limit.');
        }

        return $combined;
    }

    public function down(): void
    {
        // Non-destructive by design: a CSS edit made in Filament must never be
        // deleted or reverted by rolling back a schema/data migration.
    }
};
