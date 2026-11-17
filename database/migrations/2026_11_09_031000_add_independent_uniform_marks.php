<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('jersey_back_number_x')->default(735);
            $table->unsignedSmallInteger('jersey_back_number_y')->default(371);
            $table->decimal('jersey_back_number_scale', 4, 2)->default(1);
            $table->boolean('logo_front_enabled')->default(true);
            $table->unsignedSmallInteger('logo_front_x')->default(273);
            $table->unsignedSmallInteger('logo_front_y')->default(256);
            $table->decimal('logo_front_scale', 4, 2)->default(1);
            $table->boolean('logo_back_enabled')->default(true);
            $table->unsignedSmallInteger('logo_back_x')->default(735);
            $table->unsignedSmallInteger('logo_back_y')->default(245);
            $table->decimal('logo_back_scale', 4, 2)->default(1);
            $table->boolean('logo_shorts_enabled')->default(false);
            $table->unsignedSmallInteger('logo_shorts_x')->default(275);
            $table->unsignedSmallInteger('logo_shorts_y')->default(686);
            $table->decimal('logo_shorts_scale', 4, 2)->default(1);
        });

        // Respect legacy saved logo position when enabling independent placement.
        DB::table('uniform_designs')->where('logo_front_position', 'none')->update(['logo_front_enabled' => false]);
        DB::table('uniform_designs')->where('logo_back_position', 'none')->update(['logo_back_enabled' => false]);
        DB::table('uniform_designs')->where('logo_front_position', 'right')->update(['logo_front_x' => 182]);
        DB::table('uniform_designs')->where('logo_back_position', 'below')->update(['logo_back_y' => 432]);
    }

    public function down(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->dropColumn([
                'jersey_back_number_x',
                'jersey_back_number_y',
                'jersey_back_number_scale',
                'logo_front_enabled',
                'logo_front_x',
                'logo_front_y',
                'logo_front_scale',
                'logo_back_enabled',
                'logo_back_x',
                'logo_back_y',
                'logo_back_scale',
                'logo_shorts_enabled',
                'logo_shorts_x',
                'logo_shorts_y',
                'logo_shorts_scale',
            ]);
        });
    }
};
