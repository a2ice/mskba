<?php

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\ContractSubjectTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('subject_type', array_column(ContractSubjectTypeEnum::cases(), 'value'));
            $table->unsignedBigInteger('subject_id');
            $table->string('permission');
            $table->enum('status', array_column(ContractStatusEnum::cases(), 'value'))
                ->default(ContractStatusEnum::ACTIVE->value);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'subject_type', 'subject_id', 'permission']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
