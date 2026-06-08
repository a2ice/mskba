<?php

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->enum('family', array_column(ContractFamilyEnum::cases(), 'value'))
                ->default(ContractFamilyEnum::MEMBERSHIP->value);
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

        Schema::create('contract_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('access_level');
            $table->timestamps();

            $table->unique('contract_id');
            $table->index(['scope_type', 'scope_id']);
            $table->index(['user_id', 'scope_type']);
            $table->index(['scope_type', 'access_level']);
        });

        Schema::create('contract_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('relation_type');
            $table->string('left_type');
            $table->unsignedBigInteger('left_id');
            $table->string('left_role');
            $table->string('right_type');
            $table->unsignedBigInteger('right_id');
            $table->string('right_role');
            $table->timestamps();

            $table->unique('contract_id');
            $table->index(['relation_type']);
            $table->index(['left_type', 'left_id']);
            $table->index(['right_type', 'right_id']);
        });

        Schema::create('contract_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->timestamps();

            $table->unique(['contract_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_permissions');
        Schema::dropIfExists('contract_relations');
        Schema::dropIfExists('contract_memberships');
        Schema::dropIfExists('contracts');
    }
};
