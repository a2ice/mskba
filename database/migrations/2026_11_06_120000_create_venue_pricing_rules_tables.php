<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('venue_court_id')->nullable()->constrained('venue_courts')->cascadeOnDelete();
            $table->uuid('rule_key');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 16)->default('draft'); // draft / published / archived
            $table->boolean('is_enabled')->default(true);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->smallInteger('priority')->default(100);
            $table->boolean('stop_processing')->default(false);
            $table->json('conditions');
            $table->string('operation', 32);
            $table->unsignedBigInteger('value');
            $table->string('funding_source', 24)->default('venue');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['venue_id', 'rule_key', 'version'], 'venue_pricing_rule_version_unique');
            $table->index(['venue_id', 'status', 'is_enabled', 'priority'], 'venue_pricing_active_index');
            $table->index(['venue_court_id', 'status']);
        });

        Schema::create('venue_court_pricing_rule_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            $table->uuid('rule_key');
            $table->boolean('is_excluded')->default(true);
            $table->timestamps();
            $table->unique(['venue_court_id', 'rule_key'], 'court_pricing_rule_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_pricing_rule_overrides');
        Schema::dropIfExists('venue_pricing_rules');
    }
};
