<?php

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique()->nullable();
            $table->string('name')->nullable();
            $table->enum('status', array_column(ContractStatusEnum::cases(), 'value'))
                ->default(ContractStatusEnum::ACTIVE->value);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->enum('assigner', array_column(UserParticipationRoleAssignerEnum::cases(), 'value'));
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('venue_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'venue_id']);
            $table->index('venue_id');
        });

        Schema::create('venue_contract_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_contract_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->timestamps();

            $table->unique(['venue_contract_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_contract_permissions');
        Schema::dropIfExists('venue_contracts');
        Schema::dropIfExists('contracts');
    }
};
