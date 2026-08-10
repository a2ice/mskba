<?php

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->enum('format', array_column(GameFormatEnum::cases(), 'value'))
                ->nullable()
                ->after('status');
            $table->unsignedSmallInteger('periods_count')->nullable()->after('scoring_type');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['format', 'periods_count']);
        });
    }
};
