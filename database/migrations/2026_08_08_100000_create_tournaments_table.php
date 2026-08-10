<?php

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('title', 150);
            $table->string('alias', 180);
            $table->enum('status', array_column(TournamentStatusEnum::cases(), 'value'))
                ->default(TournamentStatusEnum::CONFIRMED->value);
            $table->text('status_comment')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('short_description')->nullable();
            $table->text('full_description')->nullable();
            $table->enum('format', [
                GameFormatEnum::BASKETBALL_5X5->value,
                GameFormatEnum::STREETBALL_3X3->value,
                GameFormatEnum::STREETBALL_1X1->value,
            ])->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('alias');
            $table->index(['status', 'starts_on']);
            $table->index(['starts_on', 'ends_on']);
            $table->index(['created_by_actor_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tournaments ADD CONSTRAINT tournaments_period_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
