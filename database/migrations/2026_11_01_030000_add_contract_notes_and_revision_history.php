<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            // The legacy comment column carries a machine marker for public
            // invitations; editable notes are kept completely separate.
            $table->text('notes')->nullable();
        });

        Schema::create('contract_change_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->text('reason');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_change_logs');
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
