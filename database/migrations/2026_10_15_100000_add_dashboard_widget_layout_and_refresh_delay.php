<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->string('image_position', 12)->default('top');
            $table->unsignedTinyInteger('image_width_percent')->default(100);
            $table->unsignedTinyInteger('image_gap_px')->default(10);
            $table->unsignedTinyInteger('refresh_delay_seconds')->default(5);
            $table->timestamp('last_edit_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn([
                'image_position', 'image_width_percent', 'image_gap_px',
                'refresh_delay_seconds', 'last_edit_at',
            ]);
        });
    }
};
