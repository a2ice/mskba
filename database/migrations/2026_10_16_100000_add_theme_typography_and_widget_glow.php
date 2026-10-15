<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_themes', function (Blueprint $table): void {
            $table->string('body_font', 30)->default('inter');
            $table->string('heading_font', 30)->default('inter');
            $table->string('widget_glow_color', 7)->nullable();
            $table->unsignedTinyInteger('widget_glow_strength')->default(20);
        });

        Schema::table('design_theme_revisions', function (Blueprint $table): void {
            $table->string('body_font', 30)->default('inter');
            $table->string('heading_font', 30)->default('inter');
            $table->string('widget_glow_color', 7)->nullable();
            $table->unsignedTinyInteger('widget_glow_strength')->default(20);
        });

        DB::transaction(function (): void {
            $theme = DB::table('design_themes')->where('slug', 'court_day')->lockForUpdate()->first();
            if (! $theme) {
                return;
            }

            // Previous V4 palette used an invisible #00000000 corner glow.
            // Do not overwrite a custom existing CSS color from Filament.
            $customized = ! str_contains((string) $theme->css_code, '--surface-corner-glow: #00000000;');
            if ($customized) {
                return;
            }

            DB::table('design_themes')->where('id', $theme->id)->update([
                'widget_glow_color' => '#BCDDF6',
                'widget_glow_strength' => 20,
                'updated_at' => now(),
            ]);

            $snapshot = [
                'design_theme_id' => $theme->id,
                'name' => $theme->name,
                'description' => $theme->description,
                'mode' => $theme->mode,
                'colors' => $theme->colors,
                'css_code' => $theme->css_code,
                'is_published' => $theme->is_published,
                'body_font' => 'inter',
                'heading_font' => 'inter',
                'widget_glow_color' => '#BCDDF6',
                'widget_glow_strength' => 20,
                'created_at' => now(),
            ];

            // Fresh installations should retain a single initial revision.
            // An edited theme on dev receives a new, reversible snapshot.
            $revisionCount = DB::table('design_theme_revisions')
                ->where('design_theme_id', $theme->id)->count();
            if ($revisionCount === 1) {
                DB::table('design_theme_revisions')->where('design_theme_id', $theme->id)
                    ->update([
                        'widget_glow_color' => '#BCDDF6',
                        'widget_glow_strength' => 20,
                    ]);
            } else {
                DB::table('design_theme_revisions')->insert($snapshot);
            }
        });
    }

    public function down(): void
    {
        Schema::table('design_theme_revisions', function (Blueprint $table): void {
            $table->dropColumn(['body_font', 'heading_font', 'widget_glow_color', 'widget_glow_strength']);
        });

        Schema::table('design_themes', function (Blueprint $table): void {
            $table->dropColumn(['body_font', 'heading_font', 'widget_glow_color', 'widget_glow_strength']);
        });
    }
};
