<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_integration_settings', function (Blueprint $table): void {
            $table->string('scope', 64);
            $table->string('provider', 20);
            $table->text('encrypted_settings');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->primary(['scope', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_integration_settings');
    }
};
