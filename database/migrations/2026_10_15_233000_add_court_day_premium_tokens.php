<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $theme = DB::table('design_themes')->where('slug', 'court_day')->lockForUpdate()->first();
            if (! $theme) {
                return;
            }

            $css = (string) $theme->css_code;
            $template = (string) config('design_palettes.day.css_code');

            // Add only missing tokens. A font/gradient already configured
            // manually in Filament must never be overwritten by deploy.
            preg_match_all(
                '/^\s*(--light-(?:display|premium)-[a-z-]+):\s*([^;]+);/m',
                $template,
                $matches,
                PREG_SET_ORDER,
            );
            $new = [];
            foreach ($matches as $match) {
                if (! preg_match('/^\s*'.preg_quote($match[1], '/').'\s*:/m', $css)) {
                    $new[] = '    '.$match[1].': '.trim($match[2]).';';
                }
            }

            if ($new === []) {
                return;
            }

            $updated = preg_replace('/}\s*$/', implode("\n", $new)."\n}\n", $css, 1);
            if ($updated === null || $updated === $css) {
                throw new \RuntimeException('Theme premium tokens could not be inserted.');
            }

            DB::table('design_themes')->where('id', $theme->id)->update([
                'css_code' => $updated,
                'updated_at' => now(),
            ]);
            DB::table('design_theme_revisions')->insert([
                'design_theme_id' => $theme->id,
                'name' => $theme->name,
                'description' => $theme->description,
                'mode' => $theme->mode,
                'colors' => $theme->colors,
                'css_code' => $updated,
                'is_published' => $theme->is_published,
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Preserve subsequent edits and reversible theme history.
    }
};
