<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_participation_roles', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable();
        });

        Schema::table('profile_change_requests', function (Blueprint $table): void {
            $table->string('scope', 32)->default('account');
            $table->index(['user_id', 'scope', 'status'], 'profile_change_requests_user_scope_status');
        });
    }

    public function down(): void
    {
        Schema::table('profile_change_requests', function (Blueprint $table): void {
            $table->dropIndex('profile_change_requests_user_scope_status');
            $table->dropColumn('scope');
        });

        Schema::table('user_participation_roles', function (Blueprint $table): void {
            $table->dropColumn('confirmed_at');
        });
    }
};
