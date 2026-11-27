<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_content_publications', function (Blueprint $table): void {
            // Historical publications were all photo-mode; never downgrade pending jobs.
            $table->boolean('requires_cover')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('vk_content_publications', function (Blueprint $table): void {
            $table->dropColumn('requires_cover');
        });
    }
};
