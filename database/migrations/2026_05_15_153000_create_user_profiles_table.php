<?php

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', array_column(UserGenderEnum::cases(), 'value'))->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
