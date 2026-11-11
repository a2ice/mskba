<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_dashboard_widget_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('widget_id')->constrained('account_dashboard_widgets')->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'widget_id'], 'dashboard_widget_preference_user_widget_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_dashboard_widget_preferences');
    }
};
