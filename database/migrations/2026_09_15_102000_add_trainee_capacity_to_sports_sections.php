<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trainee_capacity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropColumn('trainee_capacity');
        });
    }
};
