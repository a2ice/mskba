<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_group_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            // One base interest group per venue in task 297; retain a stable
            // key for future named subgroups without tying offers to aliases.
            $table->string('group_key', 80);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('code_ciphertext', 512);
            $table->char('code_hash', 64)->unique();
            $table->string('status', 16)->default('draft');
            $table->string('discount_type', 12); // percent | fixed
            $table->unsignedInteger('discount_value'); // percent points | minor units
            $table->char('currency', 3);
            $table->string('scope', 12)->nullable();
            $table->foreignId('venue_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->unsignedBigInteger('minimum_amount_minor')->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->nullable();
            $table->unsignedInteger('max_total_uses')->nullable();
            $table->unsignedInteger('max_uses_per_account')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('terms_version')->default(1);
            $table->timestampsTz();
            $table->index(['venue_id', 'group_key', 'status', 'valid_from'], 'venue_group_offer_visibility_idx');
        });

        Schema::create('venue_group_offer_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('venue_group_offers')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('action', 20);
            $table->string('previous_status', 16)->nullable();
            $table->string('next_status', 16);
            $table->unsignedInteger('terms_version');
            $table->timestampTz('created_at');
            $table->index(['offer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_group_offer_audits');
        Schema::dropIfExists('venue_group_offers');
    }
};
