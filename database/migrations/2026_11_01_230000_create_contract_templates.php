<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type', 50);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('name', 150);
            $table->json('permissions');
            $table->boolean('shared')->default(false);
            $table->timestamps();

            $table->index(['owner_user_id', 'scope_type']);
            $table->index(['scope_type', 'scope_id', 'shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
