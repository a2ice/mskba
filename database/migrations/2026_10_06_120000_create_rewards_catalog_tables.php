<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('mechanism_code', 96)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_enabled', 'mechanism_code']);
        });

        Schema::create('reward_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_id')->constrained('rewards')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('RUB');
            $table->string('mechanism_code', 96)->nullable();
            $table->text('conditions')->nullable();
            $table->text('recipient_description');
            $table->text('trigger_description');
            $table->json('mechanism_parameters')->nullable();
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['reward_id', 'version_number']);
            $table->index(['reward_id', 'valid_from', 'valid_until'], 'reward_versions_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_versions');
        Schema::dropIfExists('rewards');
    }
};
