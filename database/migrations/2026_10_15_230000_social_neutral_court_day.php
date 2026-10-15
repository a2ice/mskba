<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $record = DB::table('design_themes')->where('slug', 'court_day')->lockForUpdate()->first();
            if (! $record) {
                return;
            }

            // Only replace values from the previously shipped V3 preset.
            // Values customized in Filament must remain untouched.
            $oldColors = [
                'bg' => '#F8FAFE',
                'surface-raised' => '#EDF4FC',
                'field' => '#F7FAFF',
                'text' => '#1A2838',
                'muted' => '#506073',
                'faint' => '#5E7082',
                'line' => '#C6D8EA',
                'control-line' => '#7D91A5',
            ];

            $colors = json_decode($record->colors, true, 512, JSON_THROW_ON_ERROR);
            $originalColors = $colors;
            foreach ($oldColors as $token => $original) {
                if (($colors[$token] ?? null) === $original) {
                    $colors[$token] = config('design_palettes.day.colors.'.$token);
                }
            }

            $oldCss = [
                '--header-bg' => '#f6fafffa',
                '--body-glow' => '#c5daf030',
                '--ambient-shadow' => '#375a791a',
                '--surface-glint' => '#ffffff9c',
                '--glass-bg' => '#f7fbffee',
                '--tile-bg' => '#fff0e5',
                '--tile-border' => '#dfb08d',
                '--avatar-bg' => '#fff0e5',
                '--avatar-border' => '#c47d47',
                '--subtle-white-bg' => '#d8e8f899',
                '--shadow-accent' => '0 8px 24px #bd4f0018',
                '--shadow-menu' => '#27475e24',
                '--shadow-popover' => '#27475e26',
                '--shadow-modal' => '#27475e30',
                '--loader-bg' => '#e9f3ffde',
                '--surface-corner-glow' => '#b5d5f442',
                '--light-page-top' => '#ffffff',
                '--light-page-center' => '#f9fbfe',
                '--light-page-bottom' => '#eef5fc',
                '--light-title-top' => '#172332',
                '--light-title-bottom' => '#3a526d',
                '--light-card-blue' => '#e9f3ff',
                '--light-card-amber' => '#fff7ed',
                '--light-card-shadow' => '#274e7914',
                '--light-card-shadow-hover' => '#244d7626',
                '--light-card-line' => '#c9dbee',
                '--light-button-top' => '#c15400',
                '--light-button-bottom' => '#983c00',
                '--light-nav-active-top' => '#fff9f1',
                '--light-nav-active-bottom' => '#f2e7dc',
            ];

            $css = (string) $record->css_code;
            $newCss = (string) config('design_palettes.day.css_code');
            foreach ($oldCss as $name => $original) {
                if (preg_match('/^\s*'.preg_quote($name, '/').':\s*([^;]+);/m', $newCss, $match)) {
                    // Exact-value guard: preserve an administrator's edits.
                    $css = str_replace(
                        "{$name}: {$original};",
                        "{$name}: ".trim($match[1]).';',
                        $css,
                    );
                }
            }

            $oldDescription = 'Светлый глянцевый паркет, ледяные и тёплые карточки, фирменный оранжевый.';
            $description = $record->description === $oldDescription
                ? config('design_palettes.day.description')
                : $record->description;

            if ($colors === $originalColors && $css === $record->css_code
                && $description === $record->description) {
                return;
            }

            DB::table('design_themes')->where('id', $record->id)->update([
                'colors' => json_encode($colors, JSON_THROW_ON_ERROR),
                'css_code' => $css,
                'description' => $description,
                'updated_at' => now(),
            ]);
            DB::table('design_theme_revisions')->insert([
                'design_theme_id' => $record->id,
                'name' => $record->name,
                'description' => $description,
                'mode' => $record->mode,
                'colors' => json_encode($colors, JSON_THROW_ON_ERROR),
                'css_code' => $css,
                'is_published' => $record->is_published,
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Editorial theme history and later manual changes must not be erased.
    }
};
