<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_update_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_item_id')->unique()->constrained('content_items')->cascadeOnDelete();
            $table->string('source_key', 160)->nullable()->unique();
            $table->string('pr_url', 2048)->nullable();
            $table->string('task_reference', 80)->nullable();
            $table->json('documentation_paths')->nullable();
            $table->string('source', 80)->nullable();
            $table->timestamp('production_verified_at')->nullable();
            $table->foreignId('production_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_update_details');
    }
};
