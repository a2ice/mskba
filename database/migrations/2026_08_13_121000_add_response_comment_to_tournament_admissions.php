<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_admissions', fn (Blueprint $table) => $table->text('response_comment')->nullable()->after('comment'));
    }

    public function down(): void
    {
        Schema::table('tournament_admissions', fn (Blueprint $table) => $table->dropColumn('response_comment'));
    }
};
