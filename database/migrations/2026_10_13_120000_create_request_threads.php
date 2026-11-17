<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_permission_cursors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('permission', 128);
            $t->unsignedBigInteger('after_request_id')->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });
        Schema::create('request_threads', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 64);
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('lead_user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['type', 'subject_id']);
            $t->index(['type', 'lead_user_id']);
        });
        Schema::create('request_thread_memberships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('request_thread_id')->constrained('request_threads')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('invited_by_user_id')->nullable()->constrained('users');
            $t->timestamp('joined_at');
            $t->timestamp('removed_at')->nullable();
            $t->foreignId('removed_by_user_id')->nullable()->constrained('users');
            $t->string('removal_reason', 32)->nullable();
            $t->timestamps();
            $t->unique(['request_thread_id', 'user_id'], 'request_member_unique');
            $t->index(['user_id', 'removed_at']);
        });
        Schema::create('request_thread_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('request_thread_id')->constrained('request_threads')->cascadeOnDelete();
            $t->foreignId('sender_user_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
            $t->index(['request_thread_id', 'id']);
        });
        Schema::create('request_thread_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('request_thread_id')->constrained('request_threads')->cascadeOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users');
            $t->foreignId('target_user_id')->nullable()->constrained('users');
            $t->string('action', 40);
            $t->timestamp('created_at');
            $t->index(['request_thread_id', 'id']);
        });
        // Existing confirmed superadmins had review access before this feature.
        // New superadmins receive a cursor at their first eligible assignment,
        // never inherit visibility of older requests.
        DB::table('users')->where('system_role', 'superadmin')->where('status', 'confirmed')
            ->orderBy('id')->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    DB::table('review_permission_cursors')->insertOrIgnore([
                        'user_id' => $user->id, 'permission' => 'identity.profile_change.review',
                        'after_request_id' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        // Preserve existing profile change applications; do not rewrite their data.
        DB::table('profile_change_requests')->orderBy('id')->chunkById(200, function ($requests): void {
            foreach ($requests as $request) {
                DB::table('request_threads')->insertOrIgnore([
                    'type' => 'profile_change', 'subject_id' => $request->id,
                    'created_at' => $request->created_at, 'updated_at' => $request->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_thread_events');
        Schema::dropIfExists('request_thread_messages');
        Schema::dropIfExists('request_thread_memberships');
        Schema::dropIfExists('request_threads');
        Schema::dropIfExists('review_permission_cursors');
    }
};
