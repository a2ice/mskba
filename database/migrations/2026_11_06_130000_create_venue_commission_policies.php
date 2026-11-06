<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_commission_policies', static function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 16)->default('none'); // none | percent | fixed
            $table->unsignedBigInteger('value')->default(0); // basis points or currency minor units
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_commission_agents', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('mode', 16)->default('none');
            $table->unsignedBigInteger('value')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['venue_id', 'user_id'], 'venue_commission_agent_user_unique');
        });

        Schema::create('venue_commission_policies', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->unique()->constrained('venues')->cascadeOnDelete();
            // NULL => inherit portal commission; explicit "none" => 0 for this venue.
            $table->string('mode_override', 16)->nullable();
            $table->unsignedBigInteger('value_override')->nullable();
            // Multiple agents may exist, but exactly one may contribute to a quote.
            $table->foreignId('selected_agent_id')->nullable()
                ->constrained('venue_commission_agents')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_court_commission_policies', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('venue_court_id')->unique()->constrained('venue_courts')->cascadeOnDelete();
            $table->string('mode_override', 16)->nullable(); // inherit venue, or global if unset
            $table->unsignedBigInteger('value_override')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_commission_policies');
        Schema::dropIfExists('venue_commission_policies');
        Schema::dropIfExists('venue_commission_agents');
        Schema::dropIfExists('portal_commission_policies');
    }
};
