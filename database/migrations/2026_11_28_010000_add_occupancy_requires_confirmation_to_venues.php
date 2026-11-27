<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('occupancy_requires_confirmation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn('occupancy_requires_confirmation');
        });
    }
};
