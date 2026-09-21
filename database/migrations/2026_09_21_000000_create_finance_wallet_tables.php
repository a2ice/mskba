<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('type', 32)->default('main');
            $table->string('currency', 3)->default('RUB');
            $table->bigInteger('real_balance_minor')->default(0);
            $table->bigInteger('bonus_balance_minor')->default(0);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'wallets_owner_index');
            $table->unique(
                ['owner_type', 'owner_id', 'type', 'currency'],
                'wallets_owner_type_currency_unique',
            );
        });

        Schema::create('wallet_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 48);
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_key', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20);
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_key'], 'wallet_operations_reference_index');
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('operation_id')->constrained('wallet_operations')->restrictOnDelete();
            $table->string('balance_type', 16);
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_after_minor');
            $table->timestamps();

            $table->index(['wallet_id', 'id'], 'wallet_ledger_wallet_index');
            $table->index(['operation_id', 'id'], 'wallet_ledger_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_operations');
        Schema::dropIfExists('wallets');
    }
};
