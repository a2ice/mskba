<?php

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('created_by_actor_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table
                ->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->string('name');
            $table->string('alias')->unique();
            $table->enum('type', array_column(VenueTypeEnum::cases(), 'value'));
            $table->enum('status', array_column(VenueStatusEnum::cases(), 'value'))->default(VenueStatusEnum::UNCONFIRMED->value);
            $table->text('description')->nullable();
            $table->text('raw_address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
