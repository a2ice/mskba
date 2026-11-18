<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->string('banner_display_mode', 20)->default('top');
            $table->unsignedTinyInteger('banner_overlay_opacity')->default(50);
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn(['banner_display_mode', 'banner_overlay_opacity']);
        });
    }
};
