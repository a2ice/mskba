<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_ownership_claims DROP CONSTRAINT IF EXISTS venue_ownership_claims_status_check');
            DB::statement("ALTER TABLE venue_ownership_claims ADD CONSTRAINT venue_ownership_claims_status_check CHECK (status IN ('draft','pending','approved','rejected','cancelled'))");
        }
        Schema::table('venue_ownership_claims', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->change();
        });
        Schema::create('venue_ownership_claim_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_ownership_claim_id')->constrained('venue_ownership_claims')->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::table('venue_ownership_claims')->where('status', 'draft')->update([
            'status' => 'cancelled', 'active_marker' => null, 'cancelled_at' => now(),
        ]);
        DB::table('venue_ownership_claims')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);
        Schema::dropIfExists('venue_ownership_claim_documents');
        Schema::table('venue_ownership_claims', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable(false)->change();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_ownership_claims DROP CONSTRAINT IF EXISTS venue_ownership_claims_status_check');
            DB::statement("ALTER TABLE venue_ownership_claims ADD CONSTRAINT venue_ownership_claims_status_check CHECK (status IN ('pending','approved','rejected','cancelled'))");
        }
    }
};
