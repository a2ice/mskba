<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_claim_id')->nullable()->unique()->constrained('venue_ownership_claims')->nullOnDelete();
            $table->foreignId('contract_membership_id')->unique()->constrained('contract_memberships')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->text('status_reason')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['venue_id', 'active_marker'], 'venue_ownerships_one_current');
            $table->index(['status', 'status_changed_at']);
        });

        Schema::create('venue_ownership_claim_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_ownership_claim_id')->constrained('venue_ownership_claims')->cascadeOnDelete();
            $table->foreignId('uploaded_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['venue_ownership_claim_id', 'id'], 'venue_ownership_claim_documents_page');
        });

        Schema::create('venue_ownership_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_ownership_id')->constrained('venue_ownerships')->cascadeOnDelete();
            $table->string('type', 48);
            $table->foreignId('source_claim_document_id')->nullable()->constrained('venue_ownership_claim_documents')->nullOnDelete();
            $table->foreignId('source_claim_message_id')->nullable()->constrained('venue_ownership_claim_messages')->nullOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['venue_ownership_id', 'type']);
        });

        Schema::create('venue_user_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('reason');
            $table->foreignId('imposed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('imposed_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['venue_id', 'user_id', 'type', 'active_marker'], 'venue_user_restrictions_one_active');
            $table->index(['venue_id', 'type', 'active_marker']);
        });

        DB::table('venue_ownership_claims')
            ->where('status', 'approved')
            ->whereNotNull('owner_contract_membership_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $claim): void {
                if (DB::table('venue_ownerships')->where('venue_id', $claim->venue_id)->where('active_marker', true)->exists()) {
                    return;
                }

                $membership = DB::table('contract_memberships')->where('id', $claim->owner_contract_membership_id)->first();
                if ($membership === null) {
                    return;
                }

                DB::table('venue_ownerships')->insert([
                    'public_id' => (string) Str::uuid(),
                    'venue_id' => $claim->venue_id,
                    'owner_user_id' => $membership->user_id,
                    'source_claim_id' => $claim->id,
                    'contract_membership_id' => $membership->id,
                    'status' => 'active',
                    'approved_at' => $claim->decided_at ?? $claim->submitted_at ?? now(),
                    'active_marker' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('contract_memberships')
            ->join('contracts', 'contracts.id', '=', 'contract_memberships.contract_id')
            ->where('contract_memberships.scope_type', 'venue')
            ->where('contract_memberships.access_level', 'owner')
            ->where('contracts.status', 'active')
            ->select(['contract_memberships.id', 'contract_memberships.scope_id', 'contract_memberships.user_id', 'contracts.starts_at'])
            ->orderBy('contract_memberships.id')
            ->get()
            ->each(function (object $membership): void {
                if (DB::table('venue_ownerships')->where('venue_id', $membership->scope_id)->where('active_marker', true)->exists()) {
                    return;
                }

                DB::table('venue_ownerships')->insert([
                    'public_id' => (string) Str::uuid(),
                    'venue_id' => $membership->scope_id,
                    'owner_user_id' => $membership->user_id,
                    'source_claim_id' => null,
                    'contract_membership_id' => $membership->id,
                    'status' => 'active',
                    'approved_at' => $membership->starts_at ?? now(),
                    'active_marker' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_user_restrictions');
        Schema::dropIfExists('venue_ownership_documents');
        Schema::dropIfExists('venue_ownership_claim_documents');
        Schema::dropIfExists('venue_ownerships');
    }
};
