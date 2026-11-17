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

            // Only migrate unchanged values from the first system preset.
            // Never overwrite subsequent edits made through Filament.
            $legacyColors = [
                'bg' => '#F7F8FA', 'surface-raised' => '#EEF1F4', 'field' => '#F8F9FA',
                'text' => '#17191D', 'muted' => '#505B66', 'faint' => '#5A6672',
                'line' => '#D9DEE3', 'control-line' => '#858F9A', 'info' => '#1F5E98',
            ];
            $colors = json_decode($record->colors, true, 512, JSON_THROW_ON_ERROR);
            foreach ($legacyColors as $token => $original) {
                if (($colors[$token] ?? null) === $original) {
                    $colors[$token] = config('design_palettes.day.colors.'.$token);
                }
            }

            $legacyCss = [
                '--header-bg' => '#fffffffa',
                '--body-glow' => '#bd4f000a',
                '--ambient-shadow' => '#19212a18',
                '--surface-glint' => '#ffffff00',
                '--glass-bg' => '#ffffffed',
                '--subtle-white-bg' => '#20252b07',
                '--shadow-menu' => '#19212a24',
                '--shadow-popover' => '#19212a26',
                '--shadow-modal' => '#19212a2a',
                '--loader-bg' => '#ffffffd9',
                '--surface-corner-glow' => '#bd4f0012',
            ];
            $newCss = config('design_palettes.day.css_code');
            $css = (string) $record->css_code;
            foreach ($legacyCss as $token => $original) {
                preg_match('/^\s*'.preg_quote($token, '/').':\s*([^;]+);/m', $newCss, $matches);
                if (isset($matches[1])) {
                    $css = str_replace(
                        "{$token}: {$original};", "{$token}: ".trim($matches[1]).';', $css,
                    );
                }
            }

            $description = $record->description === 'Светлая арена, чистые карточки и тёплые баскетбольные акценты.'
                ? config('design_palettes.day.description')
                : $record->description;

            if ($colors === json_decode($record->colors, true, 512, JSON_THROW_ON_ERROR)
                && $css === $record->css_code && $description === $record->description) {
                return;
            }

            DB::table('design_themes')->where('id', $record->id)->update([
                'colors' => json_encode($colors, JSON_THROW_ON_ERROR),
                'css_code' => $css,
                'description' => $description,
                'updated_at' => now(),
            ]);
            DB::table('design_theme_revisions')->insert([
                'design_theme_id' => $record->id, 'name' => $record->name,
                'description' => $description, 'mode' => $record->mode,
                'colors' => json_encode($colors, JSON_THROW_ON_ERROR),
                'css_code' => $css, 'is_published' => $record->is_published,
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Palette revisions are user-editable data. Never undo later admin edits.
    }
};
