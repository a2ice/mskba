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

            // Upgrade only the known system preset values. Do not override
            // manual ColorPicker/CSS editor changes made in Filament.
            $old = [
                'bg' => '#EBF3FC',
                'surface-raised' => '#E6F1FC',
                'field' => '#F4F9FF',
            ];
            $colors = json_decode($record->colors, true, 512, JSON_THROW_ON_ERROR);
            foreach ($old as $key => $value) {
                if (($colors[$key] ?? null) === $value) {
                    $colors[$key] = config('design_palettes.day.colors.'.$key);
                }
            }

            $css = (string) $record->css_code;
            $knownCss = [
                '--body-glow' => '#a4cef338',
                '--surface-glint' => '#c4dff54d',
                '--surface-corner-glow' => '#a3d3f354',
            ];
            $newCss = config('design_palettes.day.css_code');
            foreach ($knownCss as $key => $value) {
                preg_match('/^\s*'.preg_quote($key, '/').':\s*([^;]+);/m', $newCss, $matches);
                if (isset($matches[1])) {
                    $css = str_replace("{$key}: {$value};", "{$key}: ".trim($matches[1]).';', $css);
                }
            }

            // New editor tokens: append only those not yet defined. Existing
            // administrator overrides always win.
            preg_match_all('/^\s*(--light-[a-z-]+):\s*([^;]+);/m', $newCss, $tokens, PREG_SET_ORDER);
            foreach ($tokens as $token) {
                $key = $token[1];
                if (! preg_match('/^\s*'.preg_quote($key, '/').':/m', $css)) {
                    $end = strrpos($css, '}');
                    if ($end !== false) {
                        $css = substr_replace($css, "    {$key}: ".trim($token[2]).";\n", $end, 0);
                    }
                }
            }

            $oldDescription = 'Ледяной голубой фон, белые карточки и тёплые баскетбольные акценты.';
            $description = $record->description === $oldDescription
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
        // Style history is editable data. Rollback must not erase later edits.
    }
};
