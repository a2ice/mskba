<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['design_themes', 'design_theme_revisions'] as $table) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->string('sidebar_background', 7)->nullable();
                $schema->string('sidebar_text', 7)->nullable();
                $schema->string('sidebar_hover', 7)->nullable();
                $schema->string('sidebar_active_background', 7)->nullable();
                $schema->string('sidebar_active_text', 7)->nullable();
            });
        }

        DB::transaction(function (): void {
            $theme = DB::table('design_themes')->where('slug', 'court_day')->lockForUpdate()->first();
            if (! $theme) {
                return;
            }

            $palette = [
                'sidebar_background' => '#FFFFFF',
                'sidebar_text' => '#59636F',
                'sidebar_hover' => '#F7F8FA',
                'sidebar_active_background' => '#FFF4EB',
                'sidebar_active_text' => '#BD4F00',
            ];
            DB::table('design_themes')->where('id', $theme->id)->update($palette + ['updated_at' => now()]);
            DB::table('design_theme_revisions')->where('design_theme_id', $theme->id)
                ->orderByDesc('id')->limit(1)->update($palette);
        });
    }

    public function down(): void
    {
        foreach (['design_theme_revisions', 'design_themes'] as $table) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->dropColumn([
                    'sidebar_background', 'sidebar_text', 'sidebar_hover',
                    'sidebar_active_background', 'sidebar_active_text',
                ]);
            });
        }
    }
};
