<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            // Legacy previews had short sleeves. Keep their appearance after migration.
            $table->string('sleeve_style', 16)->default('sleeved');
        });
    }

    public function down(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->dropColumn('sleeve_style');
        });
    }
};
