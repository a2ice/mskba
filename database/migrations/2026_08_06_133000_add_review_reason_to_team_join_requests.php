<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_join_requests', function (Blueprint $table): void {
            $table->text('review_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('team_join_requests', function (Blueprint $table): void {
            $table->dropColumn('review_reason');
        });
    }
};
