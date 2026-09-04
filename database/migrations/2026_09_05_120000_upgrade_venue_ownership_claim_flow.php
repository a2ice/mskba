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
        Schema::table('venue_ownership_claims', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
        });

        DB::table('venue_ownership_claims')
            ->select(['id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $claim): void {
                DB::table('venue_ownership_claims')
                    ->where('id', $claim->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        Schema::create('venue_ownership_claim_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_ownership_claim_id')
                ->unique()
                ->constrained('venue_ownership_claims')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_ownership_claim_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')
                ->constrained('venue_ownership_claim_conversations')
                ->cascadeOnDelete();
            $table->foreignId('author_actor_id')->constrained('actors')->restrictOnDelete();
            $table->uuid('client_id');
            $table->string('type', 16)->default('text');
            $table->text('body')->nullable();
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['conversation_id', 'author_actor_id', 'client_id'],
                'venue_ownership_claim_message_client_unique',
            );
            $table->index(
                ['conversation_id', 'id'],
                'venue_ownership_claim_message_page',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_ownership_claim_messages');
        Schema::dropIfExists('venue_ownership_claim_conversations');

        Schema::table('venue_ownership_claims', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
