<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->unique()->constrained('venues')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->unsignedInteger('freshness_ttl_seconds')->default(900);
            $table->unsignedSmallInteger('horizon_days')->default(14);
            $table->boolean('refresh_on_visit')->default(false);
            $table->boolean('scheduled_refresh')->default(false);
            $table->string('mode', 16)->default('test');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_integration_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_integration_id')->constrained('venue_integrations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('external_id', 190)->nullable();
            $table->string('adapter_key', 32)->default('manual');
            $table->text('endpoint_url')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('capabilities')->nullable();
            $table->string('permission_status', 24)->default('unchecked');
            $table->text('permission_reference')->nullable();
            $table->timestamp('access_reviewed_at')->nullable();
            // Laravel encrypted:array; NEVER expose in resource serialization or audit.
            $table->text('credentials')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['venue_integration_id', 'enabled', 'priority'], 'venue_sources_runtime_idx');
        });

        Schema::create('venue_integration_court_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_integration_source_id')
                ->constrained('venue_integration_sources')->cascadeOnDelete();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            $table->string('external_court_id', 190)->nullable();
            $table->timestamps();
            $table->unique(['venue_integration_source_id', 'venue_court_id'], 'source_court_unique');
        });

        Schema::create('venue_integration_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_integration_id')->constrained('venue_integrations')->cascadeOnDelete();
            $table->foreignId('venue_integration_source_id')->nullable()
                ->constrained('venue_integration_sources')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('changes')->nullable(); // explicitly whitelisted non-secret fields only
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['venue_integration_id', 'created_at'], 'integration_audit_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_integration_audit_events');
        Schema::dropIfExists('venue_integration_court_mappings');
        Schema::dropIfExists('venue_integration_sources');
        Schema::dropIfExists('venue_integrations');
    }
};
