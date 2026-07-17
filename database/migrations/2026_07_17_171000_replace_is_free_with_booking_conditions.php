<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('requires_payment')->default(false)->after('type');
            $table->boolean('requires_booking_approval')->default(false)->after('requires_payment');
        });

        DB::table('venues')
            ->where('is_free', false)
            ->update([
                'requires_payment' => true,
                'requires_booking_approval' => true,
            ]);

        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn('is_free');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('is_free')->default(true)->after('type');
        });

        DB::table('venues')
            ->where('requires_payment', true)
            ->orWhere('requires_booking_approval', true)
            ->update(['is_free' => false]);

        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn(['requires_payment', 'requires_booking_approval']);
        });
    }
};
