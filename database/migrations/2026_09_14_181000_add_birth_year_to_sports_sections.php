<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedSmallInteger('birth_year_from')->nullable();
            $table->unsignedSmallInteger('birth_year_to')->nullable();
        });
    }

    public function down(): void
    {
    }
};
