<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_test_account')->default(false)->index();
            $table->unsignedBigInteger('test_account_number')->nullable()->unique();
        });
        Schema::create('test_account_counters', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('last_number');
        });
        DB::table('test_account_counters')->insert(['id' => 1, 'last_number' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('test_account_counters');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['test_account_number']);
            $table->dropIndex(['is_test_account']);
            $table->dropColumn(['is_test_account', 'test_account_number']);
        });
    }
};
