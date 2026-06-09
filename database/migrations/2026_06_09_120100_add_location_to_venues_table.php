<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table
                ->foreignId('location_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('locations')
                ->nullOnDelete();
            $table->text('raw_address')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('raw_address');
        });
    }
};
