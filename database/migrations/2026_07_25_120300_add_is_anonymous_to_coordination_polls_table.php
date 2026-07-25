<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coordination_polls', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false)->after('allows_vote_changes');
        });
    }

    public function down(): void
    {
        Schema::table('coordination_polls', function (Blueprint $table): void {
            $table->dropColumn('is_anonymous');
        });
    }
};
