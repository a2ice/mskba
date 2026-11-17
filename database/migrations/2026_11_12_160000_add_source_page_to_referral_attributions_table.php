<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_attributions', function (Blueprint $table): void {
            $table->string('source_page', 2000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('referral_attributions', function (Blueprint $table): void {
            $table->dropColumn('source_page');
        });
    }
};
