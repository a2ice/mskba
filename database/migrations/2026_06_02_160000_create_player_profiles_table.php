<?php

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->unique()->cascadeOnDelete();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->enum('position', array_column(PlayerPositionEnum::cases(), 'value'))->nullable();
            $table->unsignedSmallInteger('experience_started_year')->nullable();
            $table->text('comment')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profiles');
    }
};
