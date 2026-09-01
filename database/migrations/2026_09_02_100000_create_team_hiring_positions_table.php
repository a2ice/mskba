<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_hiring_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->unsignedSmallInteger('spots_total');
            $table->unsignedSmallInteger('spots_filled')->default(0);
            $table->json('positions')->nullable();
            $table->unsignedTinyInteger('minimum_experience_years')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        Schema::table('team_join_requests', function (Blueprint $table): void {
            $table->foreignId('team_hiring_position_id')
                ->nullable()
                ->after('user_id')
                ->constrained('team_hiring_positions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_join_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_hiring_position_id');
        });
        Schema::dropIfExists('team_hiring_positions');
    }
};
