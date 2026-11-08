<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->boolean('number_enabled')->default(true);
            $table->unsignedSmallInteger('jersey_number_x')->default(225);
            $table->unsignedSmallInteger('jersey_number_y')->default(394);
            $table->decimal('jersey_number_scale', 4, 2)->default(1);
            $table->unsignedSmallInteger('shorts_number_x')->default(178);
            $table->unsignedSmallInteger('shorts_number_y')->default(755);
            $table->decimal('shorts_number_scale', 4, 2)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->dropColumn([
                'number_enabled',
                'jersey_number_x', 'jersey_number_y', 'jersey_number_scale',
                'shorts_number_x', 'shorts_number_y', 'shorts_number_scale',
            ]);
        });
    }
};
