<?php

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actors', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_key')->unique();
            $table->enum('type', array_column(ActorTypeEnum::cases(), 'value'));
            $table
                ->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table
                ->foreignId('user_fingerprint_id')
                ->nullable()
                ->constrained('user_fingerprints')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'user_id']);
            $table->index(['type', 'user_fingerprint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actors');
    }
};
