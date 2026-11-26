<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_contact_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 64)->unique();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('email_public')->default(false);
            $table->boolean('phone_public')->default(false);
            $table->boolean('telegram_public')->default(true);
            $table->boolean('vk_public')->default(true);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_contact_settings');
    }
};
