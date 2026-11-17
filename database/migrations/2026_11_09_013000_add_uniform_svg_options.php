<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            // No secondary pigment is a valid neutral, monochrome design.
            $table->char('secondary_color', 7)->nullable()->default(null)->change();
            $table->unsignedSmallInteger('jersey_number')->default(1);
            $table->char('number_color', 7)->nullable();
            $table->boolean('number_outline')->default(false);
            $table->char('number_outline_color', 7)->nullable();
            $table->string('logo_front_position', 8)->default('left');
            $table->string('logo_back_position', 8)->default('above');
        });
    }

    public function down(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->dropColumn([
                'jersey_number', 'number_color', 'number_outline',
                'number_outline_color', 'logo_front_position', 'logo_back_position',
            ]);
            $table->char('secondary_color', 7)->nullable()->default('#F58220')->change();
        });
    }
};
